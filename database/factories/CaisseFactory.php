<?php

namespace Database\Factories;

use App\Enums\Statut;
use App\Models\Caisse;
use App\Models\PointDeVente;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Caisse>
 */
class CaisseFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'point_de_vente_id' => PointDeVente::factory(),
            'libelle' => 'Caisse '.fake()->randomElement(['Principale', 'Bar', 'Terrasse', 'Accueil']),
            'statut' => Statut::Actif->value,
        ];
    }

    public function inactif(): static
    {
        return $this->state(['statut' => Statut::Inactif->value]);
    }
}
