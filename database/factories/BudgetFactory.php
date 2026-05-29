<?php

namespace Database\Factories;

use App\Models\Budget;
use App\Models\PointDeVente;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Budget>
 */
class BudgetFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'point_de_vente_id' => PointDeVente::factory(),
            'mois' => now()->startOfMonth()->toDateString(),
            'montant_alloue' => fake()->randomFloat(2, 1000, 100000),
            'seuil_alerte_75' => true,
            'seuil_alerte_90' => true,
            'seuil_alerte_100' => true,
        ];
    }
}
