<?php

namespace Database\Factories;

use App\Models\CatalogueFournisseur;
use App\Models\Fournisseur;
use App\Models\MatierePremiere;
use App\Models\UniteDeMesure;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CatalogueFournisseur>
 */
class CatalogueFournisseurFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'fournisseur_id' => Fournisseur::factory(),
            'matiere_premiere_id' => MatierePremiere::factory(),
            'prix_unitaire' => fake()->randomFloat(2, 1, 1000),
            'unite_achat_id' => UniteDeMesure::factory(),
            'qte_par_unite_achat' => fake()->randomFloat(3, 1, 30),
        ];
    }
}
