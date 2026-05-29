<?php

namespace Database\Factories;

use App\Enums\Statut;
use App\Models\ModeDePaiement;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ModeDePaiement>
 */
class ModeDePaiementFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'libelle' => fake()->randomElement(['Espèces', 'Carte bancaire', 'Mobile Money', 'Virement', 'Chèque']),
            'statut' => Statut::Actif->value,
        ];
    }

    public function inactif(): static
    {
        return $this->state(['statut' => Statut::Inactif->value]);
    }
}
