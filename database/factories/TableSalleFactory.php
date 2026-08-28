<?php

namespace Database\Factories;

use App\Enums\StatutTable;
use App\Models\PointDeVente;
use App\Models\TableSalle;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TableSalle>
 */
class TableSalleFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'point_de_vente_id' => PointDeVente::factory(),
            'numero' => (string) fake()->unique()->numberBetween(1, 9999),
            'capacite' => fake()->numberBetween(1, 12),
            'statut' => StatutTable::Libre->value,
            'public_token' => TableSalle::genererPublicToken(),
        ];
    }

    public function occupee(): static
    {
        return $this->state(['statut' => StatutTable::Occupee->value]);
    }

    /** Table retirée du service : hors plan de salle, hors caisse, QR éteint. */
    public function archivee(): static
    {
        return $this->state(['archivee_le' => now()]);
    }

    /**
     * Table antérieure à l'introduction des QR : sert à exercer le backfill.
     */
    public function sansToken(): static
    {
        return $this->state(['public_token' => null]);
    }
}
