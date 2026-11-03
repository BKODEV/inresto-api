<?php

namespace Database\Factories;

use App\Enums\StatutSessionCaisse;
use App\Models\Caisse;
use App\Models\SessionCaisse;
use App\Models\User;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

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

    /**
     * Service ouvert à une date précise.
     *
     * C'est l'ouverture qui date une vente dans le reporting, pas l'horodatage
     * de la note : un fixture qui veut placer du chiffre d'affaires un jour
     * donné doit placer le SERVICE ce jour-là.
     */
    public function ouvertLe(DateTimeInterface|string $quand): static
    {
        return $this->state(fn (): array => ['ouvert_le' => Carbon::parse($quand)]);
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
