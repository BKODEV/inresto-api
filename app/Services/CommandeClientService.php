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
     * Ajoute une ligne à une commande ouverte. Le prix est figé au prix de la
     * ligne carte_produit ACTIVE du produit sur la carte ACTIVE du PDV.
     *
     * @param  array{produit_id: int, quantite: int, commentaire?: string|null}  $data
     *
     * @throws CommandeClientException
     */
    public function ajouterLigne(CommandeClient $commande, array $data, string $_userId): LigneCommandeClient
    {
        if (! $commande->estOuverte()) {
            throw CommandeClientException::ajoutLigneStatutInvalide($commande->id);
        }

        $commande->loadMissing('table');
        $prixUnitaire = $this->prixSurCarteActive((int) $commande->table->point_de_vente_id, (int) $data['produit_id']);

        if ($prixUnitaire === null) {
            throw CommandeClientException::produitHorsCarte((int) $data['produit_id']);
        }

        return DB::transaction(function () use ($commande, $data, $prixUnitaire): LigneCommandeClient {
            $ligne = $commande->lignes()->create([
                'produit_id' => $data['produit_id'],
                'quantite' => $data['quantite'],
                'prix_unitaire' => $prixUnitaire,
                'commentaire' => $data['commentaire'] ?? null,
                'statut' => Statut::Actif->value,
            ]);

            $this->recalculerMontantTotal($commande);

            return $ligne->load('produit');
        });
    }

    /**
     * Transition ouverte → envoyee_en_cuisine. Refuse si aucune ligne active.
     *
     * @throws CommandeClientException
     */
    public function envoyerEnCuisine(CommandeClient $commande, string $_userId): CommandeClient
    {
        if (! $commande->estOuverte()) {
            throw CommandeClientException::transitionInvalide($commande->statut, StatutCommandeClient::EnvoyeeEnCuisine);
        }

        $aDesLignes = $commande->lignes()->where('statut', Statut::Actif->value)->exists();

        if (! $aDesLignes) {
            throw CommandeClientException::envoiSansLigne($commande->id);
        }

        $commande->statut = StatutCommandeClient::EnvoyeeEnCuisine;
        $commande->save();

        return $commande->load(['table', 'sessionCaisse', 'lignes.produit']);
    }

    /**
     * Transition envoyee_en_cuisine → servie, AVEC déstockage atomique. Pour
     * chaque ligne active : récupère la fiche active du produit (sinon log +
     * skip), fige `fiche_technique_id`, puis décharge le stock du site
     * (matières directes + matières des sous-recettes explosées au prorata).
     * Une vente n'est jamais bloquée par un stock insuffisant.
     *
     * @throws CommandeClientException
     */
    public function servir(CommandeClient $commande, string $userId): CommandeClient
    {
        if (! $commande->estEnvoyeeEnCuisine()) {
            throw CommandeClientException::transitionInvalide($commande->statut, StatutCommandeClient::Servie);
        }

        $commande->loadMissing('table.pointDeVente');
        $siteId = (int) $commande->table->pointDeVente->site_id;

        return DB::transaction(function () use ($commande, $siteId, $userId): CommandeClient {
            $lignes = $commande->lignes()->where('statut', Statut::Actif->value)->get();

            foreach ($lignes as $ligne) {
                $this->destockerLigne($ligne, $commande->id, $siteId, $userId);
            }

            $commande->statut = StatutCommandeClient::Servie;
            $commande->save();

            return $commande->load(['table', 'sessionCaisse', 'lignes.produit']);
        });
    }

    /**
     * Clôture (encaissement) : transition servie → encaissee. Refuse si le reste
     * à payer est strictement positif. Libère la table en « à débarrasser ».
     *
     * @throws CommandeClientException
     */
    public function encaisser(CommandeClient $commande, string $_userId): CommandeClient
    {
        if (! $commande->estServie()) {
            throw CommandeClientException::transitionInvalide($commande->statut, StatutCommandeClient::Encaissee);
        }

        $reste = $commande->resteAPayer();

        if ($reste > 0) {
            throw CommandeClientException::resteAPayer($reste);
        }

        return DB::transaction(function () use ($commande): CommandeClient {
            $commande->statut = StatutCommandeClient::Encaissee;
            $commande->save();

            $commande->loadMissing('table');
            $this->tables->changerStatut($commande->table, StatutTable::ADebarrasser);

            return $commande->load(['table', 'sessionCaisse', 'lignes.produit', 'paiements.modePaiement', 'remises']);
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
