<?php

namespace Database\Factories;

use App\Models\ParametreEtablissement;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ParametreEtablissement>
 */
class ParametreEtablissementFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),
            'nom_commercial' => fake()->company(),
            'slogan' => fake()->optional()->catchPhrase(),
            'adresse' => fake()->optional()->address(),
            'telephone' => fake()->optional()->phoneNumber(),
            'email' => fake()->optional()->companyEmail(),
            'site_web' => fake()->optional()->url(),
            'raison_sociale' => fake()->optional()->company(),
            'ncc' => fake()->optional()->bothify('CI-####-?######'),
            'rccm' => fake()->optional()->bothify('CI-ABJ-####-?-#####'),
            'regime_fiscal' => fake()->optional()->randomElement(['Réel normal', 'Réel simplifié', 'TEE']),
            'devise' => 'XOF',
            // 0 par défaut : les tests qui s'intéressent à la tolérance la
            // posent explicitement, les autres gardent la règle désactivée.
            'tolerance_ecart_caisse' => 0,
            'mentions_pied_page' => fake()->optional()->sentence(),
        ];
    }
}
