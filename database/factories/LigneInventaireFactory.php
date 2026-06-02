<?php

namespace Database\Factories;

use App\Models\Inventaire;
use App\Models\LigneInventaire;
use App\Models\MatierePremiere;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LigneInventaire>
 */
class LigneInventaireFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'inventaire_id' => Inventaire::factory(),
            'matiere_premiere_id' => MatierePremiere::factory(),
            'quantite_theorique' => 0,
            'quantite_physique' => fake()->randomFloat(3, 0, 100),
            'ecart' => null,
        ];
    }
}
