<?php

namespace Database\Factories;

use App\Enums\MouvementType;
use App\Models\MatierePremiere;
use App\Models\MouvementStock;
use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MouvementStock>
 *
 * Réservée aux tests : en production, seuls les mouvements créés par le
 * StockService font foi.
 */
class MouvementStockFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'matiere_premiere_id' => MatierePremiere::factory(),
            'site_id' => Site::factory(),
            'user_id' => User::factory(),
            'type' => MouvementType::EntreeReception->value,
            'quantite' => fake()->randomFloat(3, 1, 100),
            'pmp_au_moment' => fake()->randomFloat(2, 1, 50),
        ];
    }

    public function type(MouvementType $type): static
    {
        return $this->state(['type' => $type->value]);
    }
}
