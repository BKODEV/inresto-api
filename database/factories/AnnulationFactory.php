<?php

namespace Database\Factories;

use App\Models\Annulation;
use App\Models\CommandeClient;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Annulation>
 */
class AnnulationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'annulable_type' => 'commandes_client',
            'annulable_id' => CommandeClient::factory(),
            'motif' => fake()->sentence(),
            'date_heure' => now(),
        ];
    }
}
