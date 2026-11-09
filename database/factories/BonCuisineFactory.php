<?php

namespace Database\Factories;

use App\Enums\TypeBonCuisine;
use App\Models\BonCuisine;
use App\Models\CommandeClient;
use App\Models\PointDeVente;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BonCuisine>
 */
class BonCuisineFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'commande_client_id' => CommandeClient::factory(),
            'point_de_vente_id' => PointDeVente::factory(),
            'poste_cuisine_id' => null,
            'poste_libelle' => 'Cuisine',
            'jour' => now()->toDateString(),
            'numero' => fake()->unique()->numberBetween(1, 100000),
            'type' => TypeBonCuisine::Envoi->value,
            'partie' => 1,
            'parties' => 1,
            'user_id' => User::factory(),
            'emis_le' => now(),
        ];
    }

    public function annulation(): static
    {
        return $this->state(['type' => TypeBonCuisine::Annulation->value]);
    }
}
