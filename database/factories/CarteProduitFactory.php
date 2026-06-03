<?php

namespace Database\Factories;

use App\Enums\Statut;
use App\Models\Carte;
use App\Models\CarteProduit;
use App\Models\Produit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CarteProduit>
 */
class CarteProduitFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'carte_id' => Carte::factory(),
            'produit_id' => Produit::factory(),
            'prix_vente' => fake()->randomFloat(2, 1, 50),
            'statut' => Statut::Actif->value,
        ];
    }

    public function inactif(): static
    {
        return $this->state(['statut' => Statut::Inactif->value]);
    }
}
