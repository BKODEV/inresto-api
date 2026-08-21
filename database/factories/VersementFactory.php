<?php

namespace Database\Factories;

use App\Enums\DestinationVersement;
use App\Models\Caisse;
use App\Models\SessionCaisse;
use App\Models\User;
use App\Models\Versement;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Versement>
 */
class VersementFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'caisse_id' => Caisse::factory(),
            'session_caisse_id' => SessionCaisse::factory(),
            'user_id' => User::factory(),
            'montant' => fake()->randomFloat(2, 1000, 100000),
            'apres_fermeture' => false,
            'destination' => DestinationVersement::Coffre->value,
            'date_heure' => now(),
        ];
    }

    /** Sorti après la clôture : il explique la transition, pas le théorique. */
    public function apresFermeture(): static
    {
        return $this->state(['apres_fermeture' => true]);
    }

    /** Reversement de pourboires : la seule destination qui éteint une dette. */
    public function auPersonnel(User $beneficiaire): static
    {
        return $this->state([
            'destination' => DestinationVersement::Personnel->value,
            'beneficiaire_id' => $beneficiaire->id,
        ]);
    }

    public function versLaBanque(): static
    {
        return $this->state([
            'destination' => DestinationVersement::Banque->value,
            'reference' => 'BD-'.fake()->numerify('######'),
        ]);
    }
}
