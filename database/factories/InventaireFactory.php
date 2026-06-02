<?php

namespace Database\Factories;

use App\Enums\StatutInventaire;
use App\Models\Inventaire;
use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Inventaire>
 */
class InventaireFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),
            'date_inventaire' => now()->toDateString(),
            'notes' => null,
        ];
    }

    /**
     * `user_id` et `statut` sont hors fillable (pilotés par le service).
     */
    public function configure(): static
    {
        return $this->afterMaking(function (Inventaire $inventaire): void {
            $inventaire->user_id ??= User::factory()->create()->id;
            $inventaire->statut ??= StatutInventaire::EnCours;
        });
    }

    public function cloture(): static
    {
        return $this->afterMaking(fn (Inventaire $inventaire) => $inventaire->statut = StatutInventaire::Cloture);
    }
}
