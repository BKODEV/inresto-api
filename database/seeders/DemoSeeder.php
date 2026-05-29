<?php

namespace Database\Seeders;

use App\Models\Budget;
use App\Models\Caisse;
use App\Models\Carte;
use App\Models\CatalogueFournisseur;
use App\Models\CategorieMatierePremiere;
use App\Models\CategorieProduit;
use App\Models\Fournisseur;
use App\Models\MatierePremiere;
use App\Models\ModeDePaiement;
use App\Models\PointDeVente;
use App\Models\UniteDeMesure;
use Illuminate\Database\Seeder;

/**
 * Données de démonstration pour le développement local.
 *
 * Non destiné à la production : peuple référentiels (paramètres) et module
 * Cuisine & Stock avec des données réalistes, via les factories.
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        // ── Référentiels (Paramètres) ───────────────────────────────────────
        $pointsDeVente = collect([
            ['nom' => 'Lounge INNOPLAZA', 'type' => 'Lounge'],
            ['nom' => 'Restaurant INNOPLAZA', 'type' => 'Restaurant'],
        ])->map(fn (array $data) => PointDeVente::factory()->create($data));

        CategorieProduit::factory()->count(4)->create();

        $categoriesMp = CategorieMatierePremiere::factory()
            ->count(4)
            ->sequence(
                ['libelle' => 'Fruits & Légumes'],
                ['libelle' => 'Viandes & Volailles'],
                ['libelle' => 'Produits de la mer'],
                ['libelle' => 'Épicerie'],
            )
            ->create();

        $unites = collect([
            ['libelle' => 'Kilogramme', 'symbole' => 'kg'],
            ['libelle' => 'Litre', 'symbole' => 'L'],
            ['libelle' => 'Pièce', 'symbole' => 'pce'],
            ['libelle' => 'Sac de 25 kg', 'symbole' => 'sac'],
        ])->map(fn (array $data) => UniteDeMesure::factory()->create($data));

        collect(['Espèces', 'Carte bancaire', 'Mobile Money', 'Virement'])
            ->each(fn (string $libelle) => ModeDePaiement::factory()->create(['libelle' => $libelle]));

        $pointsDeVente->each(function (PointDeVente $pdv): void {
            Caisse::factory()->create([
                'point_de_vente_id' => $pdv->id,
                'libelle' => 'Caisse principale',
            ]);
            Carte::factory()->create(['point_de_vente_id' => $pdv->id]);
        });

        // ── Cuisine & Stock ─────────────────────────────────────────────────
        $matieres = MatierePremiere::factory()
            ->count(12)
            ->recycle($categoriesMp)
            ->recycle($unites)
            ->create();

        $fournisseurs = Fournisseur::factory()->count(5)->create();

        // Catalogue : chaque fournisseur propose un sous-ensemble de matières (sans doublon).
        $fournisseurs->each(function (Fournisseur $fournisseur) use ($matieres, $unites): void {
            $matieres->random(fake()->numberBetween(2, 5))->each(
                fn (MatierePremiere $matiere) => CatalogueFournisseur::factory()->create([
                    'fournisseur_id' => $fournisseur->id,
                    'matiere_premiere_id' => $matiere->id,
                    'unite_achat_id' => $unites->random()->id,
                ])
            );
        });

        // Budget du mois courant par point de vente.
        $pointsDeVente->each(
            fn (PointDeVente $pdv) => Budget::factory()->create([
                'point_de_vente_id' => $pdv->id,
                'mois' => now()->startOfMonth()->toDateString(),
            ])
        );
    }
}
