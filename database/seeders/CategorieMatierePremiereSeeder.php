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
        // Défauts d'une instance neuve : dès que la table a servi, on n'y touche
        // plus, sinon un élément supprimé ou renommé en prod reviendrait à
        // chaque déploiement.
        if (CategorieMatierePremiere::query()->exists()) {
            return;
        }

        collect(['Fruits & Légumes', 'Viandes & Volailles', 'Produits de la mer', 'Épicerie', 'Crèmerie'])
            ->each(fn (string $libelle) => CategorieMatierePremiere::query()->firstOrCreate(
                ['libelle' => $libelle],
            ));
    }
}
