<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\StatutFicheTechnique;
use App\Exceptions\FicheTechniqueException;
use App\Models\CatalogueFournisseur;
use App\Models\FicheTechnique;
use App\Models\SousRecette;
use Illuminate\Support\Facades\DB;

/**
 * Orchestre le cycle de vie d'une fiche technique : versioning (création /
 * nouvelle version active + archivage de l'ancienne) et calcul du coût matière
 * de RÉFÉRENCE (meilleur prix catalogue, jamais le PMP).
 *
 * RÈGLE : ce module ne touche pas au stock. Toutes les écritures sont
 * transactionnelles ; les versions archivées conservent leurs lignes.
 */
class FicheTechniqueService
{
    /**
     * Crée une fiche active pour un produit. La version est `max(version) + 1`
     * sur ce produit (1 si aucune fiche), ce qui couvre aussi le cas d'un
     * produit ayant des archives mais plus d'active.
     *
     * @param  array{produit_id: int, lignes_matieres?: array<int, array<string, mixed>>, lignes_sous_recettes?: array<int, array<string, mixed>>}  $data
     */
    public function creer(array $data, string $userId): FicheTechnique
    {
        return DB::transaction(function () use ($data, $userId): FicheTechnique {
            $version = (int) (FicheTechnique::query()
                ->where('produit_id', $data['produit_id'])
                ->max('version') ?? 0) + 1;

            return $this->construire((int) $data['produit_id'], $version, $data, $userId);
        });
    }

    /**
     * Modifie une fiche = archive l'active et crée une nouvelle version active
     * (atomique). Les lignes de l'ancienne version restent intactes.
     *
     * @param  array{produit_id: int, lignes_matieres?: array<int, array<string, mixed>>, lignes_sous_recettes?: array<int, array<string, mixed>>}  $data
     *
     * @throws FicheTechniqueException
     */
    public function modifier(FicheTechnique $ficheActive, array $data, string $userId): FicheTechnique
    {
        if (! $ficheActive->estActive()) {
            throw FicheTechniqueException::modificationFicheArchivee($ficheActive->id);
        }

        return DB::transaction(function () use ($ficheActive, $data, $userId): FicheTechnique {
            $ficheActive->statut = StatutFicheTechnique::Archivee;
            $ficheActive->save();

            return $this->construire($ficheActive->produit_id, $ficheActive->version + 1, $data, $userId);
        });
    }

    /**
     * Recalcule le coût matière des fiches ACTIVES qui référencent la
     * sous-recette donnée (cascade déclenchée par le SousRecetteService).
     */
    public function recalculerDepuisSousRecette(SousRecette $sousRecette): void
    {
        FicheTechnique::query()
            ->where('statut', StatutFicheTechnique::Active->value)
            ->whereHas('lignesSousRecette', fn ($query) => $query->where('sous_recette_id', $sousRecette->id))
            ->get()
            ->each(fn (FicheTechnique $fiche) => $this->recalculerCoutMatiere($fiche));
    }

    /**
     * Recalcule et persiste `cout_matiere` (coût de référence) :
     *   - lignes matières : quantite × meilleur prix catalogue unitaire
     *     (MIN(prix_unitaire / qte_par_unite_achat)) ; 0 si hors catalogue ;
     *   - lignes sous-recettes : cout_matiere / quantite_produite × quantite
     *     (0 si quantite_produite = 0).
     */
    public function recalculerCoutMatiere(FicheTechnique $fiche): void
    {
        $fiche->loadMissing(['lignesMatiere', 'lignesSousRecette.sousRecette']);

        $coutUnitaireParMatiere = CatalogueFournisseur::query()
            ->whereIn('matiere_premiere_id', $fiche->lignesMatiere->pluck('matiere_premiere_id'))
            ->groupBy('matiere_premiere_id')
            ->selectRaw('matiere_premiere_id, MIN(prix_unitaire / NULLIF(qte_par_unite_achat, 0)) AS cout_unitaire')
            ->pluck('cout_unitaire', 'matiere_premiere_id');

        $total = $fiche->lignesMatiere->sum(
            fn ($ligne): float => (float) $ligne->quantite * (float) ($coutUnitaireParMatiere[$ligne->matiere_premiere_id] ?? 0)
        );

        $total += $fiche->lignesSousRecette->sum(function ($ligne): float {
            $quantiteProduite = (float) $ligne->sousRecette->quantite_produite;

            if ($quantiteProduite <= 0) {
                return 0.0;
            }

            return (float) $ligne->sousRecette->cout_matiere / $quantiteProduite * (float) $ligne->quantite;
        });

        $fiche->cout_matiere = round($total, 2);
        $fiche->save();
    }

    /**
     * Crée une fiche active (version donnée) avec ses lignes et calcule son
     * coût. À appeler dans une transaction.
     *
     * @param  array{lignes_matieres?: array<int, array<string, mixed>>, lignes_sous_recettes?: array<int, array<string, mixed>>}  $data
     */
    private function construire(int $produitId, int $version, array $data, string $userId): FicheTechnique
    {
        $fiche = new FicheTechnique(['produit_id' => $produitId]);
        $fiche->version = $version;
        $fiche->statut = StatutFicheTechnique::Active;
        $fiche->updated_by = $userId;
        $fiche->save();

        foreach ($data['lignes_matieres'] ?? [] as $ligne) {
            $fiche->lignesMatiere()->create([
                'matiere_premiere_id' => $ligne['matiere_premiere_id'],
                'unite_id' => $ligne['unite_id'],
                'quantite' => $ligne['quantite'],
            ]);
        }

        foreach ($data['lignes_sous_recettes'] ?? [] as $ligne) {
            $fiche->lignesSousRecette()->create([
                'sous_recette_id' => $ligne['sous_recette_id'],
                'quantite' => $ligne['quantite'],
            ]);
        }

        $this->recalculerCoutMatiere($fiche);

        return $fiche->load(['produit', 'lignesMatiere.matierePremiere', 'lignesMatiere.unite', 'lignesSousRecette.sousRecette']);
    }
}
