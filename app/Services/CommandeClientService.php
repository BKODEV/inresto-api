<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CarteStatut;
use App\Enums\MouvementType;
use App\Enums\Statut;
use App\Enums\StatutCommandeClient;
use App\Enums\StatutFicheTechnique;
use App\Enums\StatutTable;
use App\Exceptions\CommandeClientException;
use App\Exceptions\StockException;
use App\Models\Carte;
use App\Models\CarteProduit;
use App\Models\CommandeClient;
use App\Models\FicheTechnique;
use App\Models\LigneCommandeClient;
use App\Models\SessionCaisse;
use App\Models\TableSalle;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Orchestre le cycle de vie d'une commande client (salle) : ouverture sur une
 * table, ajout de lignes au prix de la carte, envoi en cuisine, puis service —
 * SEUL moment où le stock est déchargé (StockService::sortie). Toutes les
 * opérations sont transactionnelles.
 */
class CommandeClientService
{
    public function __construct(
        private readonly StockService $stock,
        private readonly TableSalleService $tables,
        private readonly SessionCuisineService $sessionsCuisine,
        private readonly BonCuisineService $bonsCuisine,
    ) {}

    /**
     * Ouvre une commande sur une table. Vérifie la disponibilité de la table, la
     * cohérence session/PDV, le périmètre utilisateur, et passe la table en
     * « occupee » dans la même transaction.
     *
     * @param  array{table_id: int, session_caisse_id: int, reservation_id?: int|null, appartement_id?: string|null, appartement_nom?: string|null, appartement_a_verifier?: bool, notes?: string|null}  $data
     *
     * @throws CommandeClientException
     */
    public function creer(array $data, string $userId): CommandeClient
    {
        /** @var TableSalle $table */
        $table = TableSalle::query()->with('pointDeVente')->findOrFail($data['table_id']);
        /** @var SessionCaisse $session */
        $session = SessionCaisse::query()->with('caisse')->findOrFail($data['session_caisse_id']);

        // Testé avant le statut : une table archivée peut être « libre », le
        // message d'indisponibilité serait alors incompréhensible.
        if ($table->estArchivee()) {
            throw CommandeClientException::tableArchivee($table->id);
        }

        if (! in_array($table->statut, [StatutTable::Libre, StatutTable::Reservee], true)) {
            throw CommandeClientException::tableIndisponible($table->id);
        }

        if (! $session->estOuverte()) {
            throw CommandeClientException::sessionFermee($session->id);
        }

        if ($session->caisse->point_de_vente_id !== $table->point_de_vente_id) {
            throw CommandeClientException::sessionAutrePointDeVente();
        }

        $this->assertPeutUtiliserSession($userId, (int) $table->pointDeVente->site_id);

        return DB::transaction(function () use ($table, $data, $userId): CommandeClient {
            $existeCommandeActive = CommandeClient::query()
                ->where('table_id', $table->id)
                ->whereIn('statut', [StatutCommandeClient::Ouverte->value, StatutCommandeClient::EnvoyeeEnCuisine->value])
                ->lockForUpdate()
                ->exists();

            if ($existeCommandeActive) {
                throw CommandeClientException::commandeActiveExistante($table->id);
            }

            $commande = new CommandeClient([
                'table_id' => $table->id,
                // Figé ici, jamais relu depuis la table : un renommage
                // ultérieur ne doit pas réécrire les documents déjà imprimés.
                'table_numero' => $table->numero,
                'session_caisse_id' => $data['session_caisse_id'],
                'reservation_id' => $data['reservation_id'] ?? null,
                'appartement_id' => $data['appartement_id'] ?? null,
                'appartement_nom' => $data['appartement_nom'] ?? null,
                'appartement_a_verifier' => $data['appartement_a_verifier'] ?? false,
                'notes' => $data['notes'] ?? null,
            ]);
            $commande->user_id = $userId;
            $commande->date_heure = now();
            $commande->save();

            $table->statut = StatutTable::Occupee;
            $table->save();

            return $commande->load(['table', 'sessionCaisse', 'lignes.produit']);
        });
    }

    /**
     * Ajoute une ligne au brouillon de la note.
     *
     * La ligne naît NON envoyée : la cuisine ne la voit pas, rien n'est
     * déstocké, et elle se retire sans motif. Elle ne part qu'à
     * {@see envoyerEnCuisine()}.
     *
     * Autorisé tant que la note n'est ni encaissée ni annulée — un repas se
     * commande en plusieurs fois, le dessert arrive après les plats.
     *
     * @param  array{produit_id: int, quantite: int, commentaire?: string|null}  $data
     *
     * @throws CommandeClientException
     */
    public function ajouterLigne(CommandeClient $commande, array $data, string $_userId): LigneCommandeClient
    {
        $this->assertNoteModifiable($commande);

        return DB::transaction(function () use ($commande, $data): LigneCommandeClient {
            $ligne = $this->creerLigne($commande, $data, null);

            $this->recalculerMontantTotal($commande);
            $this->rafraichirStatut($commande);

            return $ligne->load('produit');
        });
    }

    /**
     * Envoie un tour en cuisine.
     *
     * Geste unique de la prise de commande : les lignes du panier sont créées
     * ET expédiées en un seul appel. Une ligne enregistrée que la cuisine ne
     * verrait pas ne servirait personne — le serveur croirait avoir commandé
     * sans que rien ne cuise.
     *
     * Toutes les lignes du tour reçoivent le MÊME horodatage : c'est lui qui
     * identifie le tour et permet à la cuisine de distinguer un nouveau ticket
     * de ce qui est déjà en production. Répétable autant de fois que le repas
     * compte de services.
     *
     * Le tour donne naissance à ses bons de cuisine, un par poste concerné,
     * dans la même transaction : pas de tour sans bon, pas de bon sans tour.
     *
     * @param  array<int, array{produit_id: int, quantite: int, commentaire?: string|null}>  $lignes  Panier à créer avant l'envoi.
     *
     * @throws CommandeClientException
     */
    public function envoyerEnCuisine(CommandeClient $commande, array $lignes, string $userId): CommandeClient
    {
        $this->assertNoteModifiable($commande);

        return DB::transaction(function () use ($commande, $lignes, $userId): CommandeClient {
            foreach ($lignes as $data) {
                $this->creerLigne($commande, $data, null);
            }

            $enAttente = $commande->lignes()
                ->where('statut', Statut::Actif->value)
                ->whereNull('envoyee_le')
                ->get();

            if ($enAttente->isEmpty()) {
                throw CommandeClientException::envoiSansLigne($commande->id);
            }

            $tour = now();
            // Le service de cuisine ouvert sur le point de vente de la table.
            // `null` est licite et ne bloque rien : la cuisine peut tourner sans
            // que personne ait ouvert son service, et refuser l'envoi
            // immobiliserait la salle pour un oubli de la brigade. La ligne part
            // alors sans être imputée à une équipe — signaler vaut mieux
            // qu'empêcher de servir.
            $commande->loadMissing('table');
            $sessionCuisine = $commande->table === null
                ? null
                : $this->sessionsCuisine->sessionOuvertePour((int) $commande->table->point_de_vente_id);

            foreach ($enAttente as $ligne) {
                $ligne->envoyee_le = $tour;
                $ligne->session_cuisine_id = $sessionCuisine?->id;
                $ligne->save();
            }

            $this->bonsCuisine->emettreEnvoi($commande, $enAttente, $tour, $userId);

            $this->recalculerMontantTotal($commande);
            $this->rafraichirStatut($commande);

            return $commande->load(['table', 'sessionCaisse', 'lignes.produit']);
        });
    }

    /**
     * La cuisine annonce au pass : les lignes sont prêtes, pas encore emportées.
     *
     * `servie_le` portait ces deux faits à la fois. Ils ne sont ni au même
     * moment ni du même métier — la cuisine annonce, la salle emporte — et les
     * confondre rendait le temps de production inmesurable : le chrono comptait
     * aussi l'attente sous la lampe, qui n'est pas de la responsabilité du chef.
     *
     * AUCUN mouvement de stock ici, volontairement. L'annonce est un signal de
     * cuisine, donc réversible ({@see annulerPret()}) ; y accrocher le stock le
     * ferait battre au rythme des corrections du pass. Le déstockage reste sur
     * l'irréversible, c'est-à-dire {@see servir()}.
     *
     * `$ligneIds` restreint l'annonce à un ticket précis ; vide, toute la
     * production en cours de la note est annoncée.
     *
     * @throws CommandeClientException
     */
    public function marquerPret(CommandeClient $commande, array $ligneIds = []): CommandeClient
    {
        $this->assertNoteModifiable($commande);

        return DB::transaction(function () use ($commande, $ligneIds): CommandeClient {
            $lignes = $commande->lignes()
                ->where('statut', Statut::Actif->value)
                ->whereNotNull('envoyee_le')
                ->whereNull('prete_le')
                ->whereNull('servie_le')
                ->when($ligneIds !== [], fn ($query) => $query->whereIn('id', $ligneIds))
                ->get();

            if ($lignes->isEmpty()) {
                throw CommandeClientException::annonceSansLigne($commande->id);
            }

            $preteLe = now();
            foreach ($lignes as $ligne) {
                $ligne->prete_le = $preteLe;
                $ligne->save();
            }

            // Pas de rafraichirStatut() : rien n'a été servi. La note reste
            // « envoyée en cuisine », et c'est exact — le client n'a toujours
            // rien devant lui. La préparation est partiellement prête par
            // nature, un statut de note ne saurait pas l'exprimer.
            return $commande->load(['table', 'sessionCaisse', 'lignes.produit']);
        });
    }

    /**
     * Dévalide une annonce faite trop tôt : la ligne retourne en production.
     *
     * C'est la contrepartie qui autorise à ne rien déstocker au prêt. Une ligne
     * servie est hors d'atteinte — elle est partie en salle et le stock a bougé
     * avec elle ; la reprendre exige une annulation motivée.
     *
     * @throws CommandeClientException
     */
    public function annulerPret(CommandeClient $commande, array $ligneIds = []): CommandeClient
    {
        $this->assertNoteModifiable($commande);

        return DB::transaction(function () use ($commande, $ligneIds): CommandeClient {
            $lignes = $commande->lignes()
                ->where('statut', Statut::Actif->value)
                ->whereNotNull('prete_le')
                ->whereNull('servie_le')
                ->when($ligneIds !== [], fn ($query) => $query->whereIn('id', $ligneIds))
                ->get();

            if ($lignes->isEmpty()) {
                throw CommandeClientException::devalidationSansLigne($commande->id);
            }

            foreach ($lignes as $ligne) {
                $ligne->prete_le = null;
                $ligne->save();
            }

            return $commande->load(['table', 'sessionCaisse', 'lignes.produit']);
        });
    }

    /**
     * Marque servies les lignes parties en cuisine et pas encore servies, AVEC
     * déstockage atomique.
     *
     * `$ligneIds` restreint le service à un tour précis ; vide, toute la
     * production en attente de la note est servie.
     *
     * Le filtre sur `servie_le` est ce qui rend le multi-tour sûr : servir le
     * dessert ne doit pas redéstocker les plats du premier tour. Sans lui, la
     * dérive de stock n'apparaîtrait qu'à l'inventaire.
     *
     * Pour chaque ligne : récupère la fiche active du produit (sinon log +
     * skip), fige `fiche_technique_id`, puis décharge le stock du site
     * (matières directes + matières des sous-recettes explosées au prorata).
     * Une vente n'est jamais bloquée par un stock insuffisant.
     *
     * @throws CommandeClientException
     */
    public function servir(CommandeClient $commande, string $userId, array $ligneIds = []): CommandeClient
    {
        $this->assertNoteModifiable($commande);

        $commande->loadMissing('table.pointDeVente');
        $siteId = (int) $commande->table->pointDeVente->site_id;

        return DB::transaction(function () use ($commande, $siteId, $userId, $ligneIds): CommandeClient {
            $lignes = $commande->lignes()
                ->where('statut', Statut::Actif->value)
                ->whereNotNull('envoyee_le')
                ->whereNull('servie_le')
                // Restreint à un tour précis : la cuisine valide un ticket, pas
                // la note. Une note peut porter deux tours en production, et
                // servir le premier ne doit pas emporter le second.
                ->when($ligneIds !== [], fn ($query) => $query->whereIn('id', $ligneIds))
                ->get();

            if ($lignes->isEmpty()) {
                throw CommandeClientException::serviceSansLigne($commande->id);
            }

            $servieLe = now();
            foreach ($lignes as $ligne) {
                $this->destockerLigne($ligne, $commande->id, $siteId, $userId);
                // L'annonce au pass est FACULTATIVE : un service sans elle reste
                // permis, et l'horodate au même instant. Sans ce rattrapage, une
                // maison qui n'annonce pas laisserait `prete_le` vide sur toute
                // sa production — et le temps de cuisine deviendrait illisible
                // là où on cherche justement à le mesurer.
                if ($ligne->prete_le === null) {
                    $ligne->prete_le = $servieLe;
                }
                $ligne->servie_le = $servieLe;
                $ligne->save();
            }

            $this->rafraichirStatut($commande);

            return $commande->load(['table', 'sessionCaisse', 'lignes.produit']);
        });
    }

    /**
     * Clôture (encaissement) : transition servie → encaissee. Refuse si le reste
     * à payer est strictement positif. Libère la table en « à débarrasser ».
     *
     * @throws CommandeClientException
     */
    public function encaisser(CommandeClient $commande, string $userId): CommandeClient
    {
        if (! $commande->estServie()) {
            throw CommandeClientException::transitionInvalide($commande->statut, StatutCommandeClient::Encaissee);
        }

        $reste = $commande->resteAPayer();

        if ($reste > 0) {
            throw CommandeClientException::resteAPayer($reste);
        }

        return DB::transaction(function () use ($commande, $userId): CommandeClient {
            $commande->statut = StatutCommandeClient::Encaissee;
            // Piste d'audit : les paiements portent déjà leur auteur, mais une
            // note soldée sans aucun paiement (offerte, couverte par un avoir)
            // n'en aurait aucun. La clôture doit donc être signée elle-même.
            $commande->encaisse_par = $userId;
            $commande->encaisse_le = now();
            $commande->save();

            $commande->loadMissing('table');
            $this->tables->changerStatut($commande->table, StatutTable::ADebarrasser);

            return $commande->load(['table', 'sessionCaisse', 'lignes.produit', 'paiements.modePaiement', 'remises']);
        });
    }

    /**
     * Abandonne une commande ouverte que rien n'a jamais garnie, et rend sa
     * table au service.
     *
     * Suppression sèche, sans motif et sans trace d'annulation : il ne s'est
     * rien passé. Voir {@see CommandeClient::peutEtreLiberee()} pour la règle
     * et sa justification.
     *
     * @throws CommandeClientException
     */
    public function liberer(CommandeClient $commande): void
    {
        if (! $commande->peutEtreLiberee()) {
            throw CommandeClientException::liberationImpossible($commande->id);
        }

        DB::transaction(function () use ($commande): void {
            $commande->loadMissing('table');
            $table = $commande->table;

            $commande->delete();

            // La table n'est rendue que si elle était occupée PAR cette
            // commande : un statut posé à la main (réservée, à débarrasser) ne
            // doit pas être écrasé par un abandon.
            if ($table !== null && $table->statut === StatutTable::Occupee) {
                $this->tables->changerStatut($table, StatutTable::Libre);
            }
        });
    }

    /**
     * Déstocke une ligne via sa fiche technique active. Sans fiche active :
     * journalise et n'effectue aucun mouvement (pas de blocage).
     */
    private function destockerLigne(LigneCommandeClient $ligne, int $commandeId, int $siteId, string $userId): void
    {
        $fiche = FicheTechnique::query()
            ->where('produit_id', $ligne->produit_id)
            ->where('statut', StatutFicheTechnique::Active->value)
            ->with(['lignesMatiere', 'lignesSousRecette.sousRecette.lignes'])
            ->first();

        if ($fiche === null) {
            Log::warning('DESTOCKAGE_MANQUANT', [
                'produit_id' => $ligne->produit_id,
                'commande_id' => $commandeId,
                'raison' => 'aucune fiche active',
            ]);

            return;
        }

        // Fige la version de fiche réellement utilisée pour le déstockage.
        $ligne->fiche_technique_id = $fiche->id;
        $ligne->save();

        $quantiteLigne = (float) $ligne->quantite;

        // Matières directes de la fiche.
        foreach ($fiche->lignesMatiere as $ligneMatiere) {
            $this->sortir(
                (int) $ligneMatiere->matiere_premiere_id,
                $siteId,
                (float) $ligneMatiere->quantite * $quantiteLigne,
                $userId,
                $ligne,
            );
        }

        // Matières issues des sous-recettes, explosées au prorata.
        foreach ($fiche->lignesSousRecette as $ligneSr) {
            $sousRecette = $ligneSr->sousRecette;
            $quantiteProduite = (float) $sousRecette->quantite_produite;

            if ($quantiteProduite <= 0) {
                continue;
            }

            foreach ($sousRecette->lignes as $ligneMatiereSr) {
                $quantite = (float) $ligneMatiereSr->quantite
                    / $quantiteProduite
                    * (float) $ligneSr->quantite
                    * $quantiteLigne;

                $this->sortir((int) $ligneMatiereSr->matiere_premiere_id, $siteId, $quantite, $userId, $ligne);
            }
        }
    }

    /**
     * Appelle StockService::sortie pour une matière. Ignore les quantités nulles
     * et n'interrompt jamais la vente : une StockException (matière inactive…)
     * est journalisée puis avalée, les erreurs inattendues remontent (rollback).
     */
    private function sortir(int $matiereId, int $siteId, float $quantite, string $userId, LigneCommandeClient $reference): void
    {
        if ($quantite <= 0) {
            return;
        }

        try {
            $this->stock->sortie(
                $matiereId,
                $siteId,
                $quantite,
                MouvementType::SortieVente,
                $userId,
                $reference,
            );
        } catch (StockException $e) {
            // Stock négatif autorisé pour une vente : on ne bloque jamais.
            Log::warning('DESTOCKAGE_PARTIEL', [
                'matiere_premiere_id' => $matiereId,
                'ligne_commande_id' => $reference->id,
                'raison' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Prix de vente du produit sur la carte ACTIVE du PDV (ligne carte_produit
     * active). `null` si aucune carte active ou produit absent de la carte.
     */
    private function prixSurCarteActive(int $pointDeVenteId, int $produitId): ?string
    {
        $carteActive = Carte::query()
            ->where('point_de_vente_id', $pointDeVenteId)
            ->where('statut', CarteStatut::Active->value)
            ->first();

        if ($carteActive === null) {
            return null;
        }

        /** @var CarteProduit|null $ligneCarte */
        $ligneCarte = CarteProduit::query()
            ->where('carte_id', $carteActive->id)
            ->where('produit_id', $produitId)
            ->where('statut', Statut::Actif->value)
            ->first();

        return $ligneCarte?->prix_vente;
    }

    /**
     * Recalcule et persiste `montant_total` = Σ (quantite × prix_unitaire) sur
     * les lignes actives.
     */
    /**
     * Une note encaissée ou annulée est close : plus rien ne s'y ajoute, ne
     * s'y envoie ni ne s'y sert.
     *
     * @throws CommandeClientException
     */
    private function assertNoteModifiable(CommandeClient $commande): void
    {
        if ($commande->estEncaissee() || $commande->statut === StatutCommandeClient::Annulee) {
            throw CommandeClientException::noteClose($commande->id);
        }
    }

    /**
     * Crée une ligne au prix figé de la carte ACTIVE du point de vente.
     *
     * @param  array{produit_id: int, quantite: int, commentaire?: string|null}  $data
     *
     * @throws CommandeClientException
     */
    private function creerLigne(CommandeClient $commande, array $data, ?Carbon $envoyeeLe): LigneCommandeClient
    {
        $commande->loadMissing('table');
        $prixUnitaire = $this->prixSurCarteActive((int) $commande->table->point_de_vente_id, (int) $data['produit_id']);

        if ($prixUnitaire === null) {
            throw CommandeClientException::produitHorsCarte((int) $data['produit_id']);
        }

        return $commande->lignes()->create([
            'produit_id' => $data['produit_id'],
            'quantite' => $data['quantite'],
            'prix_unitaire' => $prixUnitaire,
            'commentaire' => $data['commentaire'] ?? null,
            'statut' => Statut::Actif->value,
            'envoyee_le' => $envoyeeLe,
        ]);
    }

    /**
     * Réaligne le statut de la note sur celui de ses lignes.
     *
     * La vérité vit désormais sur la ligne, mais le statut de la note reste
     * exposé partout — reporting, encaissement, plan de salle. Il est donc
     * dérivé et réécrit à chaque changement, exactement aux mêmes moments que
     * {@see recalculerMontantTotal()}.
     *
     * `encaissee` et `annulee` sont des décisions explicites : elles ne se
     * déduisent pas des lignes et ne sont jamais écrasées ici.
     */
    private function rafraichirStatut(CommandeClient $commande): void
    {
        if ($commande->estEncaissee() || $commande->statut === StatutCommandeClient::Annulee) {
            return;
        }

        $actives = $commande->lignes()->where('statut', Statut::Actif->value)->get();

        $commande->statut = match (true) {
            $actives->isEmpty() => StatutCommandeClient::Ouverte,
            $actives->every(fn (LigneCommandeClient $l): bool => $l->estServie()) => StatutCommandeClient::Servie,
            $actives->contains(fn (LigneCommandeClient $l): bool => $l->estEnvoyee()) => StatutCommandeClient::EnvoyeeEnCuisine,
            default => StatutCommandeClient::Ouverte,
        };

        // Date le moment où la table s'est mise à attendre son addition : c'est
        // le seul repère qui distingue un client au comptoir d'un client parti
        // sans payer. Effacé si un ajout renvoie la note en cuisine — elle
        // n'attend plus de régler, elle attend son plat.
        $commande->servie_le = $commande->statut === StatutCommandeClient::Servie
            ? ($commande->servie_le ?? now())
            : null;

        $commande->save();
    }

    private function recalculerMontantTotal(CommandeClient $commande): void
    {
        $total = $commande->lignes()
            ->where('statut', Statut::Actif->value)
            ->get()
            ->sum(fn (LigneCommandeClient $ligne): float => (float) $ligne->quantite * (float) $ligne->prix_unitaire);

        $commande->montant_total = round($total, 2);
        $commande->save();
    }

    /**
     * Câblage du point d'extension Lot B : pour ouvrir une commande sur une
     * session, l'utilisateur doit être rattaché au SITE du PDV (pivot user_site)
     * ET porter la permission session_caisse.utiliser. Le super-admin passe.
     *
     * @throws CommandeClientException
     */
    private function assertPeutUtiliserSession(string $userId, int $siteId): void
    {
        /** @var User $user */
        $user = User::query()->findOrFail($userId);

        if ($user->isSuperAdmin()) {
            return;
        }

        $aLaPermission = $user->can('session_caisse.utiliser');
        $estSurLeSite = $user->sites()->whereKey($siteId)->exists();

        if (! $aLaPermission || ! $estSurLeSite) {
            throw CommandeClientException::accesRefuse();
        }
    }
}
