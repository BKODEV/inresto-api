<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\MatierePremiere;
use App\Models\SousRecette;
use Illuminate\Support\Facades\DB;

/**
 * Orchestre le cycle de vie d'une sous-recette : entête, synchronisation
 * complète des lignes (remplacement intégral, pas de patch ligne par ligne) et
 * recalcul du coût matière.
 *
 * RÈGLE : ce module NE TOUCHE PAS au stock. La sous-recette est une définition
 * partagée sans stock propre ; `cout_matiere` n'est qu'un indicateur.
 */
class SousRecetteService
{
    public function __construct(private readonly FicheTechniqueService $ficheTechniqueService) {}

    /**
     * Crée une sous-recette avec ses lignes et calcule son coût matière.
     *
     * @param  array{libelle: string, quantite_produite: float|string, unite_id: int, lignes: array<int, array{matiere_premiere_id: int, quantite: float|string}>}  $data
     * @param  string  $userId  Réservé pour l'audit (pas de colonne dédiée au schéma actuel).
     */
    public function creer(array $data, string $userId): SousRecette
    {
        return DB::transaction(function () use ($data): SousRecette {
            $sousRecette = SousRecette::create([
                'libelle' => $data['libelle'],
                'quantite_produite' => $data['quantite_produite'],
                'unite_id' => $data['unite_id'],
            ]);

            $this->synchroniserLignes($sousRecette, $data['lignes']);

            return $sousRecette->load(['unite', 'lignes.matierePremiere', 'lignes.unite']);
        });
    }

    /**
     * Met à jour l'entête et remplace intégralement les lignes, puis recalcule
     * le coût matière.
     *
     * @param  array{libelle: string, quantite_produite: float|string, unite_id: int, lignes: array<int, array{matiere_premiere_id: int, quantite: float|string}>}  $data
     * @param  string  $userId  Réservé pour l'audit (pas de colonne dédiée au schéma actuel).
     */
    public function modifier(SousRecette $sousRecette, array $data, string $userId): SousRecette
    {
        return DB::transaction(function () use ($sousRecette, $data): SousRecette {
            $sousRecette->update([
                'libelle' => $data['libelle'],
                'quantite_produite' => $data['quantite_produite'],
                'unite_id' => $data['unite_id'],
            ]);

            $this->synchroniserLignes($sousRecette, $data['lignes']);

            return $sousRecette->load(['unite', 'lignes.matierePremiere', 'lignes.unite']);
        });
    }

    /**
     * Remplace toutes les lignes de la sous-recette par celles fournies, puis
     * recalcule le coût matière. À appeler dans une transaction.
     *
     * @param  array<int, array{matiere_premiere_id: int, quantite: float|string}>  $lignes
     */
    private function synchroniserLignes(SousRecette $sousRecette, array $lignes): void
    {
        $sousRecette->lignes()->delete();

        $uniteStockageParMatiere = MatierePremiere::query()
            ->whereIn('id', collect($lignes)->pluck('matiere_premiere_id'))
            ->pluck('unite_de_mesure_id', 'id');

        foreach ($lignes as $ligne) {
            $sousRecette->lignes()->create([
                'matiere_premiere_id' => $ligne['matiere_premiere_id'],
                // L'unité d'une ligne est TOUJOURS l'unité de stockage de la
                // matière : le coût (recalculerCoutMatiere = Σ quantite × PMP) et
                // le déstockage explosé n'appliquent aucune conversion. On
                // l'estampe côté serveur, jamais depuis le client. À ne pas
                // confondre avec `sous_recettes.unite_id` (unité produite, libre).
                'unite_id' => $uniteStockageParMatiere[$ligne['matiere_premiere_id']],
                'quantite' => $ligne['quantite'],
            ]);
        }

        $sousRecette->load('lignes');
        $sousRecette->recalculerCoutMatiere();

        // Cascade : les fiches techniques actives référençant cette sous-recette
        // voient leur coût de référence recalculé (injection, pas de couplage statique).
        $this->ficheTechniqueService->recalculerDepuisSousRecette($sousRecette);
    }
}
