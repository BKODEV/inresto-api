<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\MouvementType;
use App\Enums\Statut;
use App\Enums\StatutCommandeClient;
use App\Enums\StatutTable;
use App\Exceptions\AnnulationException;
use App\Exceptions\StockException;
use App\Models\Annulation;
use App\Models\CommandeClient;
use App\Models\FicheTechnique;
use App\Models\LigneCommandeClient;
use App\Models\Stock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Gère les annulations (journalisées dans la table polymorphe `annulations`) :
 *  - d'une ligne (soft, statut inactif) : si la commande était déjà servie, le
 *    stock déjà déchargé peut être remis en stock (remise_en_stock) ou considéré
 *    comme consommé puis jeté (perte, aucun mouvement supplémentaire) ;
 *  - d'une commande entière (avant service uniquement) : lignes inactivées,
 *    table libérée.
 */
class AnnulationService
{
    public function __construct(
        private readonly StockService $stock,
        private readonly TableSalleService $tables,
    ) {}

    /**
     * Retire une ligne.
     *
     * Deux gestes distincts derrière un seul point d'entrée : suppression sèche
     * tant que la ligne n'est pas partie en cuisine (retourne `null`), sinon
     * annulation tracée avec motif. Si la LIGNE était servie, `sort` pilote le
     * devenir du stock déjà déchargé.
     *
     * @param  array{motif?: string|null, sort?: string|null}  $data
     *
     * @throws AnnulationException
     */
    public function annulerLigne(CommandeClient $commande, LigneCommandeClient $ligne, array $data, string $userId): ?Annulation
    {
        if ($commande->estEncaissee() || $commande->statut === StatutCommandeClient::Annulee) {
            throw AnnulationException::ligneNonAnnulable($commande->id);
        }

        // Ligne encore au brouillon : la cuisine ne l'a jamais vue, rien n'a été
        // produit ni déstocké. Ce n'est pas une annulation, c'est une correction
        // de saisie — elle disparaît sans laisser de trace. Écrire une
        // `annulation` ici noierait celles qui comptent vraiment sous le bruit
        // des « le client a changé d'avis ».
        if ($ligne->estRetirableSansMotif()) {
            $this->retirerDuBrouillon($commande, $ligne);

            return null;
        }

        // Le déstockage se fait ligne à ligne : c'est l'état de la LIGNE qui dit
        // si du stock est à recréditer, pas celui de la note. Une note « servie »
        // peut porter un tour tout juste envoyé, pas encore déstocké.
        $etaitServie = $ligne->estServie();
        $sort = $etaitServie ? ($data['sort'] ?? null) : null;

        return DB::transaction(function () use ($commande, $ligne, $data, $userId, $etaitServie, $sort): Annulation {
            $annulation = $ligne->morphMany(Annulation::class, 'annulable')->create([
                'user_id' => $userId,
                'sort' => $sort,
                'motif' => $data['motif'],
            ]);

            // Remise en stock : on recrédite les matières de la fiche figée.
            // « perte » : le plat est consommé puis jeté → aucun mouvement
            // supplémentaire, le sortie_vente initial suffit (juste tracé).
            if ($etaitServie && $sort === 'remise_en_stock') {
                $this->recrediter($commande, $ligne, $annulation, $userId);
            }

            $ligne->statut = Statut::Inactif;
            $ligne->save();

            $this->recalculerMontantTotal($commande);

            return $annulation;
        });
    }

    /**
     * Annule une commande entière (ouverte ou envoyée en cuisine uniquement).
     * Aucune ligne n'a encore été déstockée à ce stade.
     *
     * @param  array{motif: string}  $data
     *
     * @throws AnnulationException
     */
    public function annulerCommande(CommandeClient $commande, array $data, string $userId): Annulation
    {
        if (! $commande->estOuverte() && ! $commande->estEnvoyeeEnCuisine()) {
            throw AnnulationException::commandeNonAnnulable($commande->id);
        }

        return DB::transaction(function () use ($commande, $data, $userId): Annulation {
            $commande->lignes()->where('statut', Statut::Actif->value)->update(['statut' => Statut::Inactif->value]);

            $commande->statut = StatutCommandeClient::Annulee;
            $commande->montant_total = 0;
            $commande->save();

            $commande->loadMissing('table');
            $this->tables->changerStatut($commande->table, StatutTable::Libre);

            return $commande->morphMany(Annulation::class, 'annulable')->create([
                'user_id' => $userId,
                'motif' => $data['motif'],
            ]);
        });
    }

    /**
     * Recrédite le stock du site pour les matières de la fiche FIGÉE de la ligne
     * (mêmes quantités qu'au déstockage), au PMP courant. Sans fiche figée :
     * journalise et ne recrédite rien. Une StockException est avalée + loggée.
     */
    private function recrediter(CommandeClient $commande, LigneCommandeClient $ligne, Annulation $annulation, string $userId): void
    {
        if ($ligne->fiche_technique_id === null) {
            Log::warning('REMISE_EN_STOCK_IMPOSSIBLE', [
                'ligne_commande_id' => $ligne->id,
                'raison' => 'aucune fiche figée',
            ]);

            return;
        }

        $fiche = FicheTechnique::query()
            ->with(['lignesMatiere', 'lignesSousRecette.sousRecette.lignes'])
            ->find($ligne->fiche_technique_id);

        if ($fiche === null) {
            Log::warning('REMISE_EN_STOCK_IMPOSSIBLE', [
                'ligne_commande_id' => $ligne->id,
                'raison' => 'fiche figée introuvable',
            ]);

            return;
        }

        $commande->loadMissing('table.pointDeVente');
        $siteId = (int) $commande->table->pointDeVente->site_id;
        $quantiteLigne = (float) $ligne->quantite;

        foreach ($fiche->lignesMatiere as $ligneMatiere) {
            $this->entrer((int) $ligneMatiere->matiere_premiere_id, $siteId, (float) $ligneMatiere->quantite * $quantiteLigne, $userId, $annulation);
        }

        foreach ($fiche->lignesSousRecette as $ligneSr) {
            $quantiteProduite = (float) $ligneSr->sousRecette->quantite_produite;

            if ($quantiteProduite <= 0) {
                continue;
            }

            foreach ($ligneSr->sousRecette->lignes as $ligneMatiereSr) {
                $quantite = (float) $ligneMatiereSr->quantite / $quantiteProduite * (float) $ligneSr->quantite * $quantiteLigne;
                $this->entrer((int) $ligneMatiereSr->matiere_premiere_id, $siteId, $quantite, $userId, $annulation);
            }
        }
    }

    /**
     * Entrée de re-crédit au PMP courant (valorisation inchangée). Quantité nulle
     * ignorée ; StockException avalée + loggée (on ne bloque jamais).
     */
    private function entrer(int $matiereId, int $siteId, float $quantite, string $userId, Annulation $reference): void
    {
        if ($quantite <= 0) {
            return;
        }

        $pmp = (float) (Stock::query()
            ->where('matiere_premiere_id', $matiereId)
            ->where('site_id', $siteId)
            ->value('pmp') ?? 0);

        try {
            $this->stock->entree(
                $matiereId,
                $siteId,
                $quantite,
                $pmp > 0 ? $pmp : 0.01,
                MouvementType::EntreeReception,
                $userId,
                $reference,
            );
        } catch (StockException $e) {
            Log::warning('REMISE_EN_STOCK_PARTIELLE', [
                'matiere_premiere_id' => $matiereId,
                'ligne_annulation_id' => $reference->id,
                'raison' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Supprime une ligne jamais partie en cuisine. Suppression réelle et non
     * passage en `inactif` : une ligne de brouillon retirée n'a aucune histoire
     * à conserver.
     */
    private function retirerDuBrouillon(CommandeClient $commande, LigneCommandeClient $ligne): void
    {
        DB::transaction(function () use ($commande, $ligne): void {
            $ligne->delete();
            $this->recalculerMontantTotal($commande);
        });
    }

    private function recalculerMontantTotal(CommandeClient $commande): void
    {
        $commande->montant_total = round($commande->montantLignesActives(), 2);
        $commande->save();
    }
}
