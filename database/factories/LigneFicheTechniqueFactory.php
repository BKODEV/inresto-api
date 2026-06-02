<?php

namespace Database\Factories;

use App\Models\FicheTechnique;
use App\Models\LigneFicheTechnique;
use App\Models\MatierePremiere;
use App\Models\UniteDeMesure;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LigneFicheTechnique>
 */
class LigneFicheTechniqueFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'fiche_technique_id' => FicheTechnique::factory(),
            'matiere_premiere_id' => MatierePremiere::factory(),
            'unite_id' => UniteDeMesure::factory(),
            'quantite' => fake()->randomFloat(3, 1, 20),
        ];
    }
}
