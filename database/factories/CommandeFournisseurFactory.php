<?php

namespace Database\Factories;

use App\Enums\StatutCommandeFournisseur;
use App\Models\CommandeFournisseur;
use App\Models\Fournisseur;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CommandeFournisseur>
 */
class CommandeFournisseurFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'fournisseur_id' => Fournisseur::factory(),
            'date_commande' => now()->toDateString(),
            'notes' => null,
        ];
    }

    /**
     * `user_id`, `numero` et `statut` sont hors fillable (gérés par le service) :
     * on les pose en dehors du mass assignment.
     */
    public function configure(): static
    {
        return $this->afterMaking(function (CommandeFournisseur $commande): void {
            $commande->user_id ??= User::factory()->create()->id;
            $commande->numero ??= 'CF-'.now()->format('Ym').'-'.str_pad(
                (string) fake()->unique()->numberBetween(1, 9999),
                4,
                '0',
                STR_PAD_LEFT
            );
            $commande->statut ??= StatutCommandeFournisseur::Brouillon;
        });
    }

    public function statut(StatutCommandeFournisseur $statut): static
    {
        return $this->afterMaking(fn (CommandeFournisseur $commande) => $commande->statut = $statut);
    }
}
