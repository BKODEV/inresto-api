<?php

namespace Database\Factories;

use App\Enums\StatutCommandeClient;
use App\Models\CommandeClient;
use App\Models\SessionCaisse;
use App\Models\TableSalle;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CommandeClient>
 */
class CommandeClientFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'table_id' => TableSalle::factory(),
            'session_caisse_id' => SessionCaisse::factory(),
            'user_id' => User::factory(),
            'date_heure' => now(),
            'statut' => StatutCommandeClient::Ouverte->value,
            'montant_total' => 0,
        ];
    }

    public function envoyeeEnCuisine(): static
    {
        return $this->state(['statut' => StatutCommandeClient::EnvoyeeEnCuisine->value]);
    }

    public function servie(): static
    {
        return $this->state(['statut' => StatutCommandeClient::Servie->value]);
    }
}
