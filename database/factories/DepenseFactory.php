<?php

namespace Database\Factories;

use App\Models\Budget;
use App\Models\Depense;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Depense>
 */
class DepenseFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'budget_id' => Budget::factory(),
            'reception_id' => null,
            'type' => 'charge_hors_stock',
            'libelle' => fake()->sentence(3),
            'montant' => fake()->randomFloat(2, 10, 5000),
            'date_depense' => now()->toDateString(),
        ];
    }

    public function achatFournisseur(): static
    {
        return $this->state(['type' => 'achat_fournisseur']);
    }
}
