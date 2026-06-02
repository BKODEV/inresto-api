<?php

namespace Database\Factories;

use App\Models\LigneReception;
use App\Models\MatierePremiere;
use App\Models\Reception;
use App\Models\UniteDeMesure;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LigneReception>
 */
class LigneReceptionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'reception_id' => Reception::factory(),
            'matiere_premiere_id' => MatierePremiere::factory(),
            'unite_id' => UniteDeMesure::factory(),
            'quantite_recue' => fake()->randomFloat(3, 1, 100),
            'prix_unitaire_reel' => fake()->randomFloat(4, 1, 50),
        ];
    }
}
