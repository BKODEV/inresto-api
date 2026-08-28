<?php

namespace Database\Factories;

use App\Enums\Statut;
use App\Models\ModeDePaiement;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ModeDePaiement>
 */
class ModeDePaiementFactory extends Factory
{
    /**
     * `compte_en_caisse` est FIGÉ à true, jamais tiré au sort : le libellé, lui,
     * est aléatoire, et laisser le hasard décider si un mode alimente le tiroir
     * rendrait tout test de rapprochement de caisse instable — le théorique
     * changerait d'une exécution à l'autre. Les tests qui ont besoin d'un mode
     * hors tiroir demandent explicitement l'état {@see self::horsTiroir()}.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'libelle' => fake()->randomElement(['Espèces', 'Carte bancaire', 'Mobile Money', 'Virement', 'Chèque']),
            'compte_en_caisse' => true,
            'ordre' => 0,
            'statut' => Statut::Actif->value,
        ];
    }

    /** Un mode dont l'argent ne passe pas par le tiroir : virement, chèque, carte. */
    public function horsTiroir(): static
    {
        return $this->state(['compte_en_caisse' => false]);
    }

    public function inactif(): static
    {
        return $this->state(['statut' => Statut::Inactif->value]);
    }
}
