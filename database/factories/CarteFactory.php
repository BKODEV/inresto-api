<?php

namespace Database\Factories;

use App\Enums\CarteStatut;
use App\Models\Carte;
use App\Models\PointDeVente;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Carte>
 */
class CarteFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'point_de_vente_id' => PointDeVente::factory(),
            'libelle' => fake()->randomElement(['Carte du midi', 'Carte du soir', 'Carte des boissons', 'Menu week-end']),
            'statut' => CarteStatut::Brouillon->value,
        ];
    }

    public function active(): static
    {
        return $this->state(['statut' => CarteStatut::Active->value]);
    }
}
