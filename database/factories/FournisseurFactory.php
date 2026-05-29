<?php

namespace Database\Factories;

use App\Enums\Statut;
use App\Models\Fournisseur;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Fournisseur>
 */
class FournisseurFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'nom' => fake()->company(),
            'telephone' => fake()->optional()->phoneNumber(),
            'email' => fake()->optional()->companyEmail(),
            'adresse' => fake()->optional()->address(),
            'conditions_paiement' => fake()->optional()->sentence(),
            'delai_livraison_jours' => fake()->optional()->numberBetween(1, 30),
            'statut' => Statut::Actif->value,
        ];
    }

    public function inactif(): static
    {
        return $this->state(['statut' => Statut::Inactif->value]);
    }
}
