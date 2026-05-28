<?php

namespace Database\Factories;

use App\Models\CategorieProduit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CategorieProduit>
 */
class CategorieProduitFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'libelle'     => fake()->words(2, true),
            'description' => fake()->optional()->sentence(),
        ];
    }
}
