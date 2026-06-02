<?php

namespace Database\Factories;

use App\Models\CommandeFournisseur;
use App\Models\Reception;
use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Reception>
 */
class ReceptionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'commande_fournisseur_id' => CommandeFournisseur::factory(),
            'site_id' => Site::factory(),
            'date_reception' => now()->toDateString(),
            'observations' => null,
        ];
    }

    /**
     * `user_id` est hors fillable (posé par le service) : on l'affecte hors
     * mass assignment.
     */
    public function configure(): static
    {
        return $this->afterMaking(function (Reception $reception): void {
            $reception->user_id ??= User::factory()->create()->id;
        });
    }
}
