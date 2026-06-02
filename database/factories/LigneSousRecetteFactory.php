<?php

namespace Database\Factories;

use App\Models\LigneSousRecette;
use App\Models\MatierePremiere;
use App\Models\SousRecette;
use App\Models\UniteDeMesure;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LigneSousRecette>
 */
class LigneSousRecetteFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'sous_recette_id' => SousRecette::factory(),
            'matiere_premiere_id' => MatierePremiere::factory(),
            'unite_id' => UniteDeMesure::factory(),
            'quantite' => fake()->randomFloat(3, 1, 20),
        ];
    }
}
