<?php

namespace Database\Seeders;

use App\Models\CategorieMatierePremiere;
use Illuminate\Database\Seeder;

/**
 * Catégories de matière première par défaut : les familles d'achat courantes
 * d'un restaurant. Données réelles d'initialisation, idempotentes.
 */
class CategorieMatierePremiereSeeder extends Seeder
{
    public function run(): void
    {
        collect(['Fruits & Légumes', 'Viandes & Volailles', 'Produits de la mer', 'Épicerie', 'Crèmerie'])
            ->each(fn (string $libelle) => CategorieMatierePremiere::query()->firstOrCreate(
                ['libelle' => $libelle],
            ));
    }
}
