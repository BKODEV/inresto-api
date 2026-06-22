<?php

namespace Database\Factories;

use App\Enums\Statut;
use App\Models\CommandeClient;
use App\Models\LigneCommandeClient;
use App\Models\Produit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LigneCommandeClient>
 */
class LigneCommandeClientFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'commande_client_id' => CommandeClient::factory(),
            'produit_id' => Produit::factory(),
            'quantite' => fake()->numberBetween(1, 5),
            'prix_unitaire' => fake()->randomFloat(2, 1, 30),
            'statut' => Statut::Actif->value,
        ];
    }
}
