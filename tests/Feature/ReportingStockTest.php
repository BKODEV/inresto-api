<?php

declare(strict_types=1);

use App\Models\Inventaire;
use App\Models\LigneInventaire;
use App\Models\MatierePremiere;
use App\Models\MouvementStock;
use App\Models\Site;
use App\Models\Stock;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\getJson;

uses(RefreshDatabase::class);

it('valorise le stock courant (stock_courant × pmp) par matière/site', function () {
    actingAsReportingUser(['reporting.stock', 'reporting.global']);

    $site = Site::factory()->create();
    $matiere = MatierePremiere::factory()->create();
    Stock::factory()->create(['matiere_premiere_id' => $matiere->id, 'site_id' => $site->id, 'stock_courant' => 10, 'pmp' => 3]);

    $data = getJson('/api/reporting/stock/valorisation')->assertOk()->json('data');

    expect($data['sites'])->toHaveCount(1);
    $ligne = $data['sites'][0]['matieres'][0];
    expect((float) $ligne['valeur'])->toBe(30.0) // 10 × 3
        ->and((float) $data['sites'][0]['total_valorise'])->toBe(30.0)
        ->and((float) $data['total_valorise'])->toBe(30.0);
});

it('ventile la consommation par type de mouvement, valorisée au pmp figé', function () {
    actingAsReportingUser(['reporting.stock', 'reporting.global']);

    $site = Site::factory()->create();
    $matiere = MatierePremiere::factory()->create();

    // 2 sorties vente (2×5=10) + 1 perte (1×4=4) ; une entrée ignorée.
    MouvementStock::factory()->create(['matiere_premiere_id' => $matiere->id, 'site_id' => $site->id, 'type' => 'sortie_vente', 'quantite' => 2, 'pmp_au_moment' => 5, 'date_mouvement' => now()]);
    MouvementStock::factory()->create(['matiere_premiere_id' => $matiere->id, 'site_id' => $site->id, 'type' => 'perte', 'quantite' => 1, 'pmp_au_moment' => 4, 'date_mouvement' => now()]);
    MouvementStock::factory()->create(['matiere_premiere_id' => $matiere->id, 'site_id' => $site->id, 'type' => 'entree_reception', 'quantite' => 100, 'pmp_au_moment' => 1, 'date_mouvement' => now()]);

    $rows = getJson('/api/reporting/stock/consommation')->assertOk()->json('data.matieres');

    expect($rows)->toHaveCount(1);
    $m = $rows[0];
    expect((float) $m['quantite_sortie'])->toBe(3.0)
        ->and((float) $m['valeur_sortie'])->toBe(14.0)
        ->and((float) $m['ventilation']['sortie_vente']['valeur'])->toBe(10.0)
        ->and((float) $m['ventilation']['perte']['valeur'])->toBe(4.0)
        ->and($m['ventilation'])->not->toHaveKey('entree_reception');
});

it('calcule ecart_total_valeur = Σ ecart × pmp sur les inventaires clôturés', function () {
    actingAsReportingUser(['reporting.stock', 'reporting.global']);

    $site = Site::factory()->create();
    $matiere = MatierePremiere::factory()->create();
    Stock::factory()->create(['matiere_premiere_id' => $matiere->id, 'site_id' => $site->id, 'stock_courant' => 5, 'pmp' => 2]);

    $inventaire = Inventaire::factory()->create(['site_id' => $site->id, 'statut' => 'cloture', 'date_inventaire' => now()]);
    LigneInventaire::factory()->create(['inventaire_id' => $inventaire->id, 'matiere_premiere_id' => $matiere->id, 'ecart' => -3]); // -3 × pmp 2 = -6

    $rows = getJson('/api/reporting/stock/ecarts-inventaire?detail=1')->assertOk()->json('data.inventaires');

    expect($rows)->toHaveCount(1);
    expect((float) $rows[0]['ecart_total_valeur'])->toBe(-6.0)
        ->and((float) $rows[0]['demarque_valeur'])->toBe(6.0)
        ->and($rows[0]['nb_lignes'])->toBe(1)
        ->and((float) $rows[0]['matieres'][0]['ecart_valeur'])->toBe(-6.0);
});

it('ne remonte en alerte que les matières sous leur seuil', function () {
    actingAsReportingUser(['reporting.stock', 'reporting.global']);

    $site = Site::factory()->create();
    $sousSeuil = MatierePremiere::factory()->create(['seuil_alerte_stock' => 10]);
    $auDessus = MatierePremiere::factory()->create(['seuil_alerte_stock' => 10]);

    Stock::factory()->create(['matiere_premiere_id' => $sousSeuil->id, 'site_id' => $site->id, 'stock_courant' => 4, 'pmp' => 1]); // sous seuil
    Stock::factory()->create(['matiere_premiere_id' => $auDessus->id, 'site_id' => $site->id, 'stock_courant' => 50, 'pmp' => 1]); // au-dessus

    $rows = getJson('/api/reporting/stock/alertes')->assertOk()->json('data.alertes');

    expect($rows)->toHaveCount(1);
    expect($rows[0]['matiere_premiere_id'])->toBe($sousSeuil->id)
        ->and((float) $rows[0]['stock_courant'])->toBe(4.0)
        ->and((float) $rows[0]['seuil'])->toBe(10.0)
        ->and((float) $rows[0]['manquant'])->toBe(6.0);
});
