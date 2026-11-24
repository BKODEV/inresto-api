<?php

namespace Database\Seeders;

use App\Models\CategorieMatierePremiere;
use App\Models\MatierePremiere;
use App\Models\UniteDeMesure;
use Illuminate\Database\Seeder;

/**
 * Matières premières usuelles d'un restaurant : définitions GLOBALES (partagées
 * par tous les sites, sans stock ni PMP ici). Suppose que les catégories et les
 * unités ont déjà été semées. Idempotent (clé naturelle = libellé).
 */
class MatierePremiereSeeder extends Seeder
{
    public function run(): void
    {
        // Défauts d'une instance neuve : dès que la table a servi, on n'y touche
        // plus, sinon un élément supprimé ou renommé en prod reviendrait à
        // chaque déploiement.
        if (MatierePremiere::withTrashed()->exists()) {
            return;
        }

        $categories = CategorieMatierePremiere::query()->get()->keyBy('libelle');
        $unites = UniteDeMesure::query()->get()->keyBy('symbole');

        // libellé => [catégorie, symbole unité de stockage]
        collect([
            'Tomate' => ['Fruits & Légumes', 'kg'],
            'Oignon' => ['Fruits & Légumes', 'kg'],
            'Pomme de terre' => ['Fruits & Légumes', 'kg'],
            'Banane plantain' => ['Fruits & Légumes', 'kg'],
            'Salade verte' => ['Fruits & Légumes', 'pce'],
            'Poulet' => ['Viandes & Volailles', 'kg'],
            'Bœuf' => ['Viandes & Volailles', 'kg'],
            'Tilapia' => ['Produits de la mer', 'kg'],
            'Crevettes' => ['Produits de la mer', 'kg'],
            'Riz' => ['Épicerie', 'kg'],
            'Huile végétale' => ['Épicerie', 'L'],
            'Sel' => ['Épicerie', 'kg'],
            'Farine de blé' => ['Épicerie', 'kg'],
            'Sucre' => ['Épicerie', 'kg'],
            'Lait' => ['Crèmerie', 'L'],
            'Fromage' => ['Crèmerie', 'kg'],
            'Œufs' => ['Crèmerie', 'pce'],
        ])->each(fn (array $meta, string $libelle) => MatierePremiere::query()->firstOrCreate(
            ['libelle' => $libelle],
            [
                'categorie_id' => $categories[$meta[0]]->id,
                'unite_de_mesure_id' => $unites[$meta[1]]->id,
            ],
        ));
    }
}
