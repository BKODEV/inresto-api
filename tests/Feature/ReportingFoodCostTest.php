<?php

declare(strict_types=1);

use App\Models\CommandeClient;
use App\Models\FicheTechnique;
use App\Models\LigneCommandeClient;
use App\Models\MouvementStock;
use App\Models\PointDeVente;
use App\Models\Produit;
use App\Models\Site;
use App\Models\TableSalle;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\getJson;

uses(RefreshDatabase::class);

/**
 * Construit un scénario de vente valorisée + une ligne non valorisée sur un même
 * site, dans le mois courant.
 *
 * @return array{site: Site, pdv: PointDeVente}
 */
function scenarioFoodCost(): array
{
    $site = Site::factory()->create();
    $pdv = PointDeVente::factory()->create(['site_id' => $site->id]);
    $table = TableSalle::factory()->create(['point_de_vente_id' => $pdv->id]);

    $commande = CommandeClient::factory()->create([
        'table_id' => $table->id,
        'statut' => 'servie',
        'date_heure' => now(),
    ]);

    // Ligne valorisée : fiche figée (coût matière 4) × 2, prix 10 → CA 20, théorique 8.
    $produit = Produit::factory()->create();
    $fiche = FicheTechnique::factory()->create(['produit_id' => $produit->id, 'cout_matiere' => 4]);
    $ligne = LigneCommandeClient::factory()->create([
        'commande_client_id' => $commande->id,
        'produit_id' => $produit->id,
        'fiche_technique_id' => $fiche->id,
        'quantite' => 2,
        'prix_unitaire' => 10,
    ]);
    // Coût réel = 2 × 5 = 10 (figé sur le mouvement).
    MouvementStock::factory()->create([
        'type' => 'sortie_vente',
        'reference_type' => 'lignes_commande_client',
        'reference_id' => $ligne->id,
        'quantite' => 2,
        'pmp_au_moment' => 5,
    ]);

    // Ligne NON valorisée : pas de fiche figée, prix 15.
    $produitSansFiche = Produit::factory()->create();
    LigneCommandeClient::factory()->create([
        'commande_client_id' => $commande->id,
        'produit_id' => $produitSansFiche->id,
        'fiche_technique_id' => null,
        'quantite' => 1,
        'prix_unitaire' => 15,
    ]);

    return ['site' => $site, 'pdv' => $pdv];
}

it('calcule food cost réel (Σ pmp_au_moment) vs théorique (fiche figée) par produit', function () {
    actingAsReportingUser(['reporting.food_cost', 'reporting.global']);
    scenarioFoodCost();

    $data = getJson('/api/reporting/food-cost/produits')->assertOk()->json('data');

    expect($data['produits'])->toHaveCount(1);

    $ligne = $data['produits'][0];
    expect((float) $ligne['quantite_vendue'])->toBe(2.0)
        ->and((float) $ligne['ca'])->toBe(20.0)
        ->and((float) $ligne['cout_theorique'])->toBe(8.0)
        ->and((float) $ligne['cout_reel'])->toBe(10.0)
        ->and((float) $ligne['food_cost_theorique_pct'])->toBe(40.0)
        ->and((float) $ligne['food_cost_reel_pct'])->toBe(50.0)
        ->and((float) $ligne['ecart_food_cost_valeur'])->toBe(2.0)
        ->and((float) $ligne['ecart_food_cost_points'])->toBe(10.0)
        ->and((float) $ligne['marge_brute_reelle'])->toBe(10.0);
});

it('isole les lignes non valorisées sans les fondre dans les ratios', function () {
    actingAsReportingUser(['reporting.food_cost', 'reporting.global']);
    scenarioFoodCost();

    $data = getJson('/api/reporting/food-cost/produits')->assertOk()->json('data');

    // Exclues du détail valorisé.
    expect($data['produits'])->toHaveCount(1);

    $bloc = $data['lignes_non_valorisees'];
    expect($bloc['nombre_lignes'])->toBe(1)
        ->and((float) $bloc['ca_concerne'])->toBe(15.0)
        ->and($bloc['produits'])->toHaveCount(1);
});

it('produit une synthèse cohérente avec le détail', function () {
    actingAsReportingUser(['reporting.food_cost', 'reporting.global']);
    scenarioFoodCost();

    $data = getJson('/api/reporting/food-cost/synthese')->assertOk()->json('data');

    expect((float) $data['ca_total'])->toBe(35.0) // 20 valorisé + 15 non valorisé
        ->and((float) $data['ca_valorise'])->toBe(20.0)
        ->and((float) $data['cout_theorique_total'])->toBe(8.0)
        ->and((float) $data['cout_reel_total'])->toBe(10.0)
        ->and((float) $data['food_cost_theorique_pct'])->toBe(40.0)
        ->and((float) $data['food_cost_reel_pct'])->toBe(50.0)
        ->and((float) $data['ecart_global_points'])->toBe(10.0)
        ->and((float) $data['marge_brute_totale'])->toBe(25.0) // 35 − 10
        ->and((float) $data['part_ca_non_valorise_pct'])->toBe(42.86); // 15 / 35
});

it('refuse une plage sans date_debut/date_fin (422)', function () {
    actingAsReportingUser(['reporting.food_cost', 'reporting.global']);

    getJson('/api/reporting/food-cost/produits?periode=plage')
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['date_debut', 'date_fin']);
});

it('refuse une plage où date_debut > date_fin (422)', function () {
    actingAsReportingUser(['reporting.food_cost', 'reporting.global']);

    getJson('/api/reporting/food-cost/produits?periode=plage&date_debut=2026-06-20&date_fin=2026-06-10')
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['date_fin']);
});
