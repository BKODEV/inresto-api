<?php

namespace Database\Factories;

use App\Models\CategorieMatierePremiere;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CategorieMatierePremiere>
 */
class CategorieMatierePremiereFactory extends Factory
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
