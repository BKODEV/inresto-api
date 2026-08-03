<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\StatutCommandeFournisseur;
use App\Exceptions\CommandeFournisseurException;
use App\Models\CatalogueFournisseur;
use App\Models\CommandeFournisseur;
use App\Models\LigneCommandeFournisseur;
use App\Models\MatierePremiere;
use Illuminate\Support\Facades\DB;

/**
 * Orchestre le cycle de vie d'une commande fournisseur : création, gestion des
 * lignes, recalcul du montant prévisionnel, génération du numéro et transitions
 * d'état.
 *
 * RÈGLE : ce module N'INTERAGIT JAMAIS avec le stock (aucun appel au
 * StockService). Le stock ne bouge qu'à la réception (module dédié).
 */
class CommandeFournisseurService
{
    /**
     * Crée une commande en brouillon, avec ses lignes éventuelles, et calcule
     * son montant prévisionnel.
     *
     * @param  array{fournisseur_id: int, date_commande?: string|null, notes?: string|null}  $entete
     * @param  array<int, array{matiere_premiere_id: int, unite_id: int, quantite_commandee: float|string, prix_unitaire_previsionnel: float|string}>  $lignes
     *
     * @throws CommandeFournisseurException
     */
    public function creer(array $entete, array $lignes, string $userId): CommandeFournisseur
    {
        return DB::transaction(function () use ($entete, $lignes, $userId): CommandeFournisseur {
            $commande = new CommandeFournisseur([
                'fournisseur_id' => $entete['fournisseur_id'],
                'date_commande' => $entete['date_commande'] ?? now()->toDateString(),
                'notes' => $entete['notes'] ?? null,
            ]);
            $commande->user_id = $userId;
            $commande->statut = StatutCommandeFournisseur::Brouillon;
            $commande->numero = $this->genererNumero();
            $commande->save();

            foreach ($lignes as $ligne) {
                $this->creerLigne($commande, $ligne);
            }

            $this->recalculerMontant($commande);

            return $this->chargerRelations($commande);
        });
    }

    /**
     * Met à jour l'entête (fournisseur, date, notes) — uniquement en brouillon.
     *
     * @param  array<string, mixed>  $donnees
     *
     * @throws CommandeFournisseurException
     */
    public function mettreAJourEntete(CommandeFournisseur $commande, array $donnees): CommandeFournisseur
    {
        $this->assertModifiable($commande);

        $commande->fill($donnees);
        $commande->save();

        return $this->chargerRelations($commande);
    }

    /**
     * Ajoute une ligne et recalcule le montant — uniquement en brouillon.
     *
     * @param  array{matiere_premiere_id: int, unite_id: int, quantite_commandee: float|string, prix_unitaire_previsionnel: float|string}  $donnees
     *
     * @throws CommandeFournisseurException
     */
    public function ajouterLigne(CommandeFournisseur $commande, array $donnees): LigneCommandeFournisseur
    {
        $this->assertModifiable($commande);

        return DB::transaction(function () use ($commande, $donnees): LigneCommandeFournisseur {
            $ligne = $this->creerLigne($commande, $donnees);
            $this->recalculerMontant($commande);

            return $ligne->load(['matierePremiere', 'unite']);
        });
    }

    /**
     * Met à jour une ligne et recalcule le montant — uniquement en brouillon.
     *
     * @param  array<string, mixed>  $donnees
     *
     * @throws CommandeFournisseurException
     */
    public function mettreAJourLigne(LigneCommandeFournisseur $ligne, array $donnees): LigneCommandeFournisseur
    {
        $commande = $ligne->commande;
        $this->assertModifiable($commande);

        return DB::transaction(function () use ($ligne, $donnees, $commande): LigneCommandeFournisseur {
            $matiereId = (int) ($donnees['matiere_premiere_id'] ?? $ligne->matiere_premiere_id);
            $this->assertMatiereActive($matiereId);
            $this->assertMatiereAbsente($commande, $matiereId, $ligne->id);

            $donnees['matiere_premiere_id'] = $matiereId;
            // L'unité suit TOUJOURS le catalogue (unité d'achat), jamais le payload.
            $donnees['unite_id'] = $this->uniteAchatCatalogue($commande->fournisseur_id, $matiereId);

            $ligne->fill($donnees);
            $ligne->save();

            $this->recalculerMontant($commande);

            return $ligne->load(['matierePremiere', 'unite']);
        });
    }

    /**
     * Supprime une ligne et recalcule le montant — uniquement en brouillon.
     *
     * @throws CommandeFournisseurException
     */
    public function supprimerLigne(LigneCommandeFournisseur $ligne): void
    {
        $commande = $ligne->commande;
        $this->assertModifiable($commande);

        DB::transaction(function () use ($ligne, $commande): void {
            $ligne->delete();
            $this->recalculerMontant($commande);
        });
    }

    /**
     * Charge les relations exposées par l'API et calcule, pour chaque ligne, ce
     * qui a DÉJÀ été reçu et ce qu'il RESTE à recevoir (en unité d'achat).
     *
     * Ces deux valeurs sont dérivées des réceptions, jamais stockées. Elles
     * alimentent le pré-remplissage du formulaire de réception : sans elles,
     * une seconde livraison partielle reproposerait la quantité commandée
     * entière et provoquerait une sur-réception.
     */
    public function chargerRelations(CommandeFournisseur $commande): CommandeFournisseur
    {
        $commande->load(['fournisseur', 'lignes.matierePremiere', 'lignes.unite']);

        $recuParMatiere = $commande->quantitesRecuesParMatiere();

        foreach ($commande->lignes as $ligne) {
            $recu = (float) ($recuParMatiere[$ligne->matiere_premiere_id] ?? 0);

            $ligne->quantite_recue = round($recu, 3);
            // Bornée à 0 : une sur-livraison ne crée pas de « reste » négatif.
            $ligne->quantite_restante = round(max((float) $ligne->quantite_commandee - $recu, 0), 3);
        }

        return $commande;
    }

    /**
     * Recalcule `montant_previsionnel` = Σ (quantite_commandee × prix_unitaire_previsionnel).
     * Jamais saisi à la main.
     */
    public function recalculerMontant(CommandeFournisseur $commande): void
    {
        $montant = $commande->lignes()
            ->get()
            ->sum(fn (LigneCommandeFournisseur $ligne): float => (float) $ligne->quantite_commandee * (float) $ligne->prix_unitaire_previsionnel);

        $commande->montant_previsionnel = $montant;
        $commande->save();
    }

    /**
     * Génère le prochain numéro unique au format CF-AAAAMM-#### (séquence par mois).
     */
    public function genererNumero(): string
    {
        $prefixe = 'CF-'.now()->format('Ym').'-';

        $dernier = CommandeFournisseur::query()
            ->where('numero', 'like', $prefixe.'%')
            ->lockForUpdate()
            ->orderByDesc('numero')
            ->value('numero');

        $sequence = $dernier !== null ? ((int) substr($dernier, -4)) + 1 : 1;

        return $prefixe.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
    }

    // ───────────────────────────── transitions ─────────────────────────────

    /**
     * brouillon -> envoyee. Exige au moins une ligne. Verrouille la commande.
     *
     * @throws CommandeFournisseurException
     */
    public function marquerEnvoyee(CommandeFournisseur $commande): CommandeFournisseur
    {
        if ($commande->lignes()->count() === 0) {
            throw CommandeFournisseurException::sansLigne();
        }

        return $this->transitionnerVers($commande, StatutCommandeFournisseur::Envoyee);
    }

    /**
     * N'importe quel état (sauf cloturee) -> annulee. Interdit si la commande a
     * déjà au moins une réception.
     *
     * @throws CommandeFournisseurException
     */
    public function annuler(CommandeFournisseur $commande): CommandeFournisseur
    {
        if ($this->aDesReceptions($commande)) {
            throw CommandeFournisseurException::annulationImpossibleAvecReception();
        }

        return $this->transitionnerVers($commande, StatutCommandeFournisseur::Annulee);
    }

    /**
     * Exposée pour le module réception (étape 3). NON appelée par ce module.
     *
     * @throws CommandeFournisseurException
     */
    public function marquerPartiellementRecue(CommandeFournisseur $commande): CommandeFournisseur
    {
        return $this->transitionnerVers($commande, StatutCommandeFournisseur::PartiellementRecue);
    }

    /**
     * Exposée pour le module réception (étape 3). NON appelée par ce module.
     *
     * @throws CommandeFournisseurException
     */
    public function marquerCloturee(CommandeFournisseur $commande): CommandeFournisseur
    {
        return $this->transitionnerVers($commande, StatutCommandeFournisseur::Cloturee);
    }

    // ───────────────────────────── interne ─────────────────────────────

    /**
     * @param  array{matiere_premiere_id: int, unite_id: int, quantite_commandee: float|string, prix_unitaire_previsionnel: float|string}  $donnees
     *
     * @throws CommandeFournisseurException
     */
    private function creerLigne(CommandeFournisseur $commande, array $donnees): LigneCommandeFournisseur
    {
        $matiereId = (int) $donnees['matiere_premiere_id'];
        $this->assertMatiereActive($matiereId);
        $this->assertMatiereAbsente($commande, $matiereId);

        return $commande->lignes()->create([
            'matiere_premiere_id' => $matiereId,
            // Unité imposée par le catalogue (unité d'achat du couple
            // fournisseur/matière) : c'est elle qui porte le facteur de
            // conversion du PMP à la réception. Jamais choisie par le client.
            'unite_id' => $this->uniteAchatCatalogue($commande->fournisseur_id, $matiereId),
            'quantite_commandee' => $donnees['quantite_commandee'],
            'prix_unitaire_previsionnel' => $donnees['prix_unitaire_previsionnel'],
        ]);
    }

    /**
     * @throws CommandeFournisseurException
     */
    private function transitionnerVers(CommandeFournisseur $commande, StatutCommandeFournisseur $cible): CommandeFournisseur
    {
        if (! $commande->statut->peutTransitionnerVers($cible)) {
            throw CommandeFournisseurException::transitionInvalide($commande->statut, $cible);
        }

        $commande->statut = $cible;
        $commande->save();

        return $commande;
    }

    /**
     * @throws CommandeFournisseurException
     */
    private function assertModifiable(CommandeFournisseur $commande): void
    {
        if (! $commande->estModifiable()) {
            throw CommandeFournisseurException::nonModifiable($commande->statut);
        }
    }

    /**
     * La matière première référencée doit exister et être active.
     *
     * @throws CommandeFournisseurException
     */
    private function assertMatiereActive(int $matierePremiereId): void
    {
        $matiere = MatierePremiere::find($matierePremiereId);
        if ($matiere === null || ! $matiere->isActif()) {
            throw CommandeFournisseurException::matiereInactive($matierePremiereId);
        }
    }

    /**
     * Une matière ne figure qu'une seule fois par commande : deux lignes sur la
     * même matière rendraient ambigu le cumul commandé/reçu qui pilote la
     * clôture de la commande. Doublé par un index unique en base.
     *
     * @param  int|null  $ligneExclueId  Ligne en cours de modification, exclue du contrôle.
     *
     * @throws CommandeFournisseurException
     */
    private function assertMatiereAbsente(CommandeFournisseur $commande, int $matierePremiereId, ?int $ligneExclueId = null): void
    {
        $existe = $commande->lignes()
            ->where('matiere_premiere_id', $matierePremiereId)
            ->when($ligneExclueId !== null, fn ($query) => $query->whereKeyNot($ligneExclueId))
            ->exists();

        if ($existe) {
            throw CommandeFournisseurException::matiereDejaPresente($matierePremiereId);
        }
    }

    /**
     * Unité d'achat du couple (fournisseur, matière) au catalogue : l'unité de
     * TOUTE ligne de commande (elle porte le facteur de conversion du PMP). La
     * règle MatiereAuCatalogue garantit son existence en amont ; défensif ici.
     *
     * @throws CommandeFournisseurException
     */
    private function uniteAchatCatalogue(int $fournisseurId, int $matiereId): int
    {
        $uniteId = CatalogueFournisseur::query()
            ->where('fournisseur_id', $fournisseurId)
            ->where('matiere_premiere_id', $matiereId)
            ->value('unite_achat_id');

        if ($uniteId === null) {
            throw CommandeFournisseurException::matiereHorsCatalogue($matiereId);
        }

        return (int) $uniteId;
    }

    /**
     * Vrai dès qu'au moins une réception est rattachée à la commande. Bloque
     * l'annulation d'une commande déjà réceptionnée.
     */
    private function aDesReceptions(CommandeFournisseur $commande): bool
    {
        return $commande->receptions()->exists();
    }
}
