<?php

namespace Database\Seeders;

use App\Models\CategorieProduit;
use Illuminate\Database\Seeder;

/**
 * Catégories de produit par défaut : les sections classiques d'une carte,
 * ordonnées (Entrées avant Plats, etc.). Données réelles d'initialisation,
 * idempotentes.
 */
class CategorieProduitSeeder extends Seeder
{
    public function run(): void
    {
        collect(['Entrées', 'Plats', 'Desserts', 'Boissons'])
            ->each(fn (string $libelle, int $i) => CategorieProduit::query()->firstOrCreate(
                ['libelle' => $libelle],
                ['ordre' => $i + 1],
            ));
    }
}
