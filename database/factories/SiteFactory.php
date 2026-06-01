<?php

namespace Database\Factories;

use App\Enums\Statut;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Site>
 */
class SiteFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'nom' => fake()->city(),
            'code' => strtoupper(fake()->unique()->bothify('SITE-###')),
            'adresse' => fake()->optional()->address(),
            'statut' => Statut::Actif->value,
        ];
    }

    public function inactif(): static
    {
        return $this->state(['statut' => Statut::Inactif->value]);
    }
}
