<?php

namespace Database\Factories;

use App\Enums\Statut;
use App\Models\PointDeVente;
use App\Models\PosteCuisine;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PosteCuisine>
 */
class PosteCuisineFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'point_de_vente_id' => PointDeVente::factory(),
            'libelle' => fake()->unique()->word(),
            'ordre' => 0,
            'statut' => Statut::Actif->value,
        ];
    }
}
