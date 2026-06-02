<?php

namespace Database\Factories;

use App\Enums\Statut;
use App\Models\CategorieProduit;
use App\Models\Produit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Produit>
 */
class ProduitFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'categorie_id' => CategorieProduit::factory(),
            'libelle' => fake()->unique()->words(2, true),
            'statut' => Statut::Actif->value,
        ];
    }

    public function inactif(): static
    {
        return $this->state(['statut' => Statut::Inactif->value]);
    }
}
