<?php

namespace Database\Factories;

use App\Models\SousRecette;
use App\Models\UniteDeMesure;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SousRecette>
 */
class SousRecetteFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'libelle' => fake()->unique()->words(2, true),
            'quantite_produite' => fake()->randomFloat(3, 1, 50),
            'unite_id' => UniteDeMesure::factory(),
            // cout_matiere reste à 0 par défaut ; recalculé par le service.
        ];
    }
}
