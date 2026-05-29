<?php

namespace Database\Factories;

use App\Enums\Statut;
use App\Models\CategorieMatierePremiere;
use App\Models\MatierePremiere;
use App\Models\UniteDeMesure;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MatierePremiere>
 */
class MatierePremiereFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'categorie_id' => CategorieMatierePremiere::factory(),
            'unite_de_mesure_id' => UniteDeMesure::factory(),
            'libelle' => fake()->words(2, true),
            'seuil_alerte_stock' => fake()->randomFloat(3, 0, 50),
            'gestion_peremption' => fake()->boolean(),
            'statut' => Statut::Actif->value,
        ];
    }

    public function inactif(): static
    {
        return $this->state(['statut' => Statut::Inactif->value]);
    }
}
