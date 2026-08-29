<?php

namespace Database\Factories;

use App\Enums\Statut;
use App\Models\CommandeClient;
use App\Models\LigneCommandeClient;
use App\Models\Produit;
use App\Services\CommandeClientService;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LigneCommandeClient>
 */
class LigneCommandeClientFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'commande_client_id' => CommandeClient::factory(),
            'produit_id' => Produit::factory(),
            'quantite' => fake()->numberBetween(1, 5),
            'prix_unitaire' => fake()->randomFloat(2, 1, 30),
            'statut' => Statut::Actif->value,
        ];
    }

    /**
     * Ligne partie en cuisine. Le cycle de vie vit sur la LIGNE : poser le
     * statut de la note ne suffit pas à rendre une ligne visible au KDS ni
     * servable.
     *
     * ⚠️ `now()` est réévalué à CHAQUE appel : deux lignes créées de part et
     * d'autre d'une seconde n'ont pas le même `envoyee_le`. Or le KDS groupe
     * ses tours par (commande, `envoyee_le`) — en production toutes les lignes
     * d'un tour reçoivent le même horodatage, posé une seule fois par
     * {@see CommandeClientService::envoyerEnCuisine()}.
     *
     * Un test qui attend PLUSIEURS lignes dans un seul tour doit donc figer le
     * temps (`$this->freezeTime()`), sinon il échoue par intermittence.
     */
    public function envoyee(): static
    {
        return $this->state(['envoyee_le' => now()->subMinutes(5)]);
    }

    /**
     * Ligne annoncée prête au pass : sortie de la file cuisine, elle attend la
     * salle. Aucun mouvement de stock ne lui est associé — le déstockage est au
     * service.
     */
    public function prete(): static
    {
        return $this->state([
            'envoyee_le' => now()->subMinutes(8),
            'prete_le' => now()->subMinutes(3),
        ]);
    }

    /** Ligne servie et déstockée : un second service ne la reprend pas. */
    public function servie(): static
    {
        return $this->state([
            'envoyee_le' => now()->subMinutes(10),
            // Une ligne servie a forcément été prête avant : l'omettre ferait
            // mentir les durées de production calculées sur les fixtures.
            'prete_le' => now()->subMinutes(4),
            'servie_le' => now()->subMinutes(2),
        ]);
    }
}
