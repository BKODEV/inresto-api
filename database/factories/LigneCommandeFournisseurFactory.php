<?php

namespace Database\Factories;

use App\Models\CommandeFournisseur;
use App\Models\LigneCommandeFournisseur;
use App\Models\MatierePremiere;
use App\Models\UniteDeMesure;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LigneCommandeFournisseur>
 */
class LigneCommandeFournisseurFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'commande_fournisseur_id' => CommandeFournisseur::factory(),
            'matiere_premiere_id' => MatierePremiere::factory(),
            'unite_id' => UniteDeMesure::factory(),
            'quantite_commandee' => fake()->randomFloat(3, 1, 100),
            'prix_unitaire_previsionnel' => fake()->randomFloat(4, 1, 50),
        ];
    }
}
