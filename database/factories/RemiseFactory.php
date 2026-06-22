<?php

namespace Database\Factories;

use App\Enums\TypeRemise;
use App\Models\CommandeClient;
use App\Models\Remise;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Remise>
 */
class RemiseFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'commande_client_id' => CommandeClient::factory(),
            'user_id' => User::factory(),
            'type' => TypeRemise::Pourcentage->value,
            'valeur' => fake()->randomFloat(2, 1, 10),
            'date_heure' => now(),
        ];
    }

    public function montantFixe(float $valeur): static
    {
        return $this->state(['type' => TypeRemise::MontantFixe->value, 'valeur' => $valeur]);
    }
}
