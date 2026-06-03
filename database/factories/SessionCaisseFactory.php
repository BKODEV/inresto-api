<?php

namespace Database\Factories;

use App\Enums\StatutSessionCaisse;
use App\Models\Caisse;
use App\Models\SessionCaisse;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SessionCaisse>
 */
class SessionCaisseFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'caisse_id' => Caisse::factory(),
            'user_id' => User::factory(),
            'ouvert_le' => now(),
            'fonds_initial' => fake()->randomFloat(2, 0, 200),
            'statut' => StatutSessionCaisse::Ouverte->value,
        ];
    }

    public function fermee(): static
    {
        return $this->state(fn (array $attributes): array => [
            'statut' => StatutSessionCaisse::Fermee->value,
            'ferme_le' => now(),
            'montant_theorique' => $attributes['fonds_initial'] ?? 0,
            'montant_reel' => $attributes['fonds_initial'] ?? 0,
            'ecart' => 0,
        ]);
    }
}
