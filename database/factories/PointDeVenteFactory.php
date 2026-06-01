<?php

namespace Database\Factories;

use App\Enums\PointDeVenteType;
use App\Enums\Statut;
use App\Models\PointDeVente;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PointDeVente>
 */
class PointDeVenteFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),
            'nom' => fake()->company(),
            'type' => fake()->randomElement(PointDeVenteType::cases())->value,
            'description' => fake()->optional()->sentence(),
            'statut' => Statut::Actif->value,
        ];
    }

    public function inactif(): static
    {
        return $this->state(['statut' => Statut::Inactif->value]);
    }
}
