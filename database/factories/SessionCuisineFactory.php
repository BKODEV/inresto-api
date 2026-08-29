<?php

namespace Database\Factories;

use App\Enums\StatutSessionCuisine;
use App\Models\PointDeVente;
use App\Models\SessionCuisine;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SessionCuisine>
 */
class SessionCuisineFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'point_de_vente_id' => PointDeVente::factory(),
            'ouvert_par' => User::factory(),
            'ouvert_le' => now(),
            'statut' => StatutSessionCuisine::Ouverte->value,
        ];
    }

    public function fermee(): static
    {
        return $this->state([
            'statut' => StatutSessionCuisine::Fermee->value,
            'ferme_le' => now(),
        ]);
    }
}
