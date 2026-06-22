<?php

namespace Database\Factories;

use App\Models\CommandeClient;
use App\Models\ModeDePaiement;
use App\Models\Paiement;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Paiement>
 */
class PaiementFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'commande_client_id' => CommandeClient::factory(),
            'mode_de_paiement_id' => ModeDePaiement::factory(),
            'user_id' => User::factory(),
            'montant' => fake()->randomFloat(2, 1, 100),
            'date_heure' => now(),
        ];
    }
}
