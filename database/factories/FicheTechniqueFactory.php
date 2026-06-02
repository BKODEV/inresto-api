<?php

namespace Database\Factories;

use App\Enums\StatutFicheTechnique;
use App\Models\FicheTechnique;
use App\Models\Produit;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FicheTechnique>
 */
class FicheTechniqueFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'produit_id' => Produit::factory(),
        ];
    }

    /**
     * `version`, `statut`, `updated_by` sont hors fillable (pilotés par le
     * service) : on les pose hors mass assignment.
     */
    public function configure(): static
    {
        return $this->afterMaking(function (FicheTechnique $fiche): void {
            $fiche->version ??= 1;
            $fiche->statut ??= StatutFicheTechnique::Active;
            $fiche->updated_by ??= User::factory()->create()->id;
        });
    }

    public function version(int $version): static
    {
        return $this->afterMaking(fn (FicheTechnique $fiche) => $fiche->version = $version);
    }

    public function archivee(): static
    {
        return $this->afterMaking(fn (FicheTechnique $fiche) => $fiche->statut = StatutFicheTechnique::Archivee);
    }
}
