<?php

namespace Database\Factories;

use App\Enums\Statut;
use App\Models\UniteDeMesure;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UniteDeMesure>
 */
class UniteDeMesureFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'libelle' => fake()->unique()->word(),
            'symbole' => fake()->unique()->lexify('??'),
            'statut' => Statut::Actif->value,
        ];
    }
}
