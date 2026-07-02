<?php

declare(strict_types=1);

use App\Models\Budget;
use App\Models\CommandeClient;
use App\Models\Depense;
use App\Models\FicheTechnique;
use App\Models\LigneCommandeClient;
use App\Models\MatierePremiere;
use App\Models\ModeDePaiement;
use App\Models\MouvementStock;
use App\Models\Paiement;
use App\Models\PointDeVente;
use App\Models\Produit;
use App\Models\Site;
use App\Models\Stock;
use App\Models\TableSalle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

use function Pest\Laravel\getJson;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Date figée au milieu du mois : « aujourd'hui » (15) ≠ « plus tôt dans le
    // mois » (02), pour distinguer CA jour et CA mois de façon déterministe.
    Carbon::setTestNow('2026-06-15 12:00:00');
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * Crée une commande vendue sur la table avec une ligne (quantite × prix) à la
 * date donnée. Retourne la commande pour d'éventuels paiements.
 */
function dashboardCommande(TableSalle $table, int $quantite, float $prix, Carbon $dateHeure, string $statut = 'servie'): CommandeClient
{
    $commande = CommandeClient::factory()->create([
        'table_id' => $table->id,
        'statut' => $statut,
        'date_heure' => $dateHeure,
    ]);

    LigneCommandeClient::factory()->create([
        'commande_client_id' => $commande->id,
        'quantite' => $quantite,
        'prix_unitaire' => $prix,
    ]);

    return $commande;
}

it('compose une réponse agrégée complète à partir des services reporting', function () {
    actingAsReportingUser(['reporting.dashboard', 'reporting.global']);

    $site = Site::factory()->create();
    $pdv = PointDeVente::factory()->create(['site_id' => $site->id]);
    $table = TableSalle::factory()->create(['point_de_vente_id' => $pdv->id]);

    // Vente valorisée aujourd'hui : CA 20, théorique 8, réel 10.
    $produit = Produit::factory()->create();
    $fiche = FicheTechnique::factory()->create(['produit_id' => $produit->id, 'cout_matiere' => 4]);
    $commande = dashboardCommande($table, 1, 100, now(), 'encaissee');
    $ligne = LigneCommandeClient::factory()->create([
        'commande_client_id' => $commande->id,
        'produit_id' => $produit->id,
        'fiche_technique_id' => $fiche->id,
        'quantite' => 2,
        'prix_unitaire' => 10,
    ]);
    MouvementStock::factory()->create([
        'type' => 'sortie_vente',
        'reference_type' => 'lignes_commande_client',
        'reference_id' => $ligne->id,
        'quantite' => 2,
        'pmp_au_moment' => 5,
    ]);

    // Encaissement (alimente modes_paiement).
    $mode = ModeDePaiement::factory()->create();
    Paiement::factory()->create(['commande_client_id' => $commande->id, 'mode_de_paiement_id' => $mode->id, 'montant' => 120]);

    // Budget du mois + dépense.
    $budget = Budget::factory()->create([
        'site_id' => $site->id,
        'mois' => now()->startOfMonth()->toDateString(),
        'montant_alloue' => 1000,
    ]);
    Depense::factory()->create(['budget_id' => $budget->id, 'montant' => 400, 'date_depense' => now()->toDateString()]);

    // Matière sous seuil (alerte stock).
    $matiere = MatierePremiere::factory()->create(['seuil_alerte_stock' => 100]);
    Stock::factory()->create(['matiere_premiere_id' => $matiere->id, 'site_id' => $site->id, 'stock_courant' => 5, 'pmp' => 2]);

    $data = getJson('/api/reporting/dashboard')->assertOk()->json('data');

    // Structure complète.
    expect($data)->toHaveKeys(['ca', 'food_cost', 'budget', 'top_produits', 'evolution_ca', 'alertes_stock', 'modes_paiement']);
    expect($data['ca'])->toHaveKeys(['jour', 'mois']);
    expect($data['ca']['jour'])->toHaveKeys(['montant', 'nb_commandes']);
    expect($data['food_cost'])->toHaveKeys(['reel_pct', 'theorique_pct', 'ecart_points', 'marge_brute']);
    expect($data['budget'])->toHaveKeys(['montant_alloue', 'montant_consomme', 'pourcentage_consomme', 'niveau_alerte']);
    expect($data['alertes_stock'])->toHaveKeys(['nombre', 'items']);

    // Valeurs clés (CA = 100 + 20 = 120 aujourd'hui).
    expect((float) $data['ca']['jour']['montant'])->toBe(120.0)
        ->and($data['ca']['jour']['nb_commandes'])->toBe(1);

    expect((float) $data['food_cost']['reel_pct'])->toBe(50.0)
        ->and((float) $data['food_cost']['theorique_pct'])->toBe(40.0)
        ->and((float) $data['food_cost']['ecart_points'])->toBe(10.0);

    expect((float) $data['budget']['montant_alloue'])->toBe(1000.0)
        ->and((float) $data['budget']['montant_consomme'])->toBe(400.0)
        ->and((float) $data['budget']['pourcentage_consomme'])->toBe(40.0)
        ->and($data['budget']['niveau_alerte'])->toBe('aucun');

    expect($data['alertes_stock']['nombre'])->toBe(1)
        ->and($data['alertes_stock']['items'][0]['matiere'])->toBe($matiere->libelle);

    expect($data['modes_paiement'])->toHaveCount(1)
        ->and((float) $data['modes_paiement'][0]['montant'])->toBe(120.0);

    expect($data['evolution_ca'])->toHaveCount(30); // 30 derniers jours
});

it('distingue le CA du jour du CA du mois', function () {
    actingAsReportingUser(['reporting.dashboard', 'reporting.global']);

    $pdv = PointDeVente::factory()->create(['site_id' => Site::factory()->create()->id]);
    $table = TableSalle::factory()->create(['point_de_vente_id' => $pdv->id]);

    dashboardCommande($table, 1, 100, now());                       // aujourd'hui : jour + mois
    dashboardCommande($table, 1, 50, now()->copy()->startOfMonth()->addDay()); // 02/06 : mois seulement

    $data = getJson('/api/reporting/dashboard')->assertOk()->json('data');

    expect((float) $data['ca']['jour']['montant'])->toBe(100.0)
        ->and($data['ca']['jour']['nb_commandes'])->toBe(1)
        ->and((float) $data['ca']['mois']['montant'])->toBe(150.0)
        ->and($data['ca']['mois']['nb_commandes'])->toBe(2);
});

it('respecte le périmètre site d\'un utilisateur non-global', function () {
    $siteAutorise = Site::factory()->create();
    $siteInterdit = Site::factory()->create();
    actingAsReportingUser(['reporting.dashboard'], [$siteAutorise->id]);

    foreach ([$siteAutorise, $siteInterdit] as $site) {
        $pdv = PointDeVente::factory()->create(['site_id' => $site->id]);
        $table = TableSalle::factory()->create(['point_de_vente_id' => $pdv->id]);
        dashboardCommande($table, 1, 100, now());

        $matiere = MatierePremiere::factory()->create(['seuil_alerte_stock' => 100]);
        Stock::factory()->create(['matiere_premiere_id' => $matiere->id, 'site_id' => $site->id, 'stock_courant' => 1, 'pmp' => 2]);
    }

    $data = getJson('/api/reporting/dashboard')->assertOk()->json('data');

    // Seul le CA et l'alerte du site autorisé remontent.
    expect((float) $data['ca']['jour']['montant'])->toBe(100.0);
    expect($data['alertes_stock']['nombre'])->toBe(1)
        ->and($data['alertes_stock']['items'][0]['site_id'])->toBe($siteAutorise->id);
});

it('refuse l\'accès sans la permission reporting.dashboard', function () {
    // Toutes les permissions reporting SAUF dashboard.
    actingAsReportingUser(['reporting.food_cost', 'reporting.ventes', 'reporting.stock', 'reporting.budget', 'reporting.global']);

    getJson('/api/reporting/dashboard')->assertForbidden();
});
