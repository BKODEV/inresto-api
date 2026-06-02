<?php

namespace Database\Factories;

use App\Models\FicheTechnique;
use App\Models\LigneFicheTechniqueSousRecette;
use App\Models\SousRecette;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LigneFicheTechniqueSousRecette>
 */
class LigneFicheTechniqueSousRecetteFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'fiche_technique_id' => FicheTechnique::factory(),
            'sous_recette_id' => SousRecette::factory(),
            'quantite' => fake()->randomFloat(3, 1, 20),
        ];
    }
}
