<?php

declare(strict_types=1);

use App\Models\MatierePremiere;
use App\Models\Site;
use App\Models\Stock;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;

use function Pest\Laravel\getJson;

uses(RefreshDatabase::class);

/**
 * Authentifie un utilisateur muni des permissions fournies.
 *
 * @param  string[]  $permissions
 */
function actingAsStockUser(array $permissions = []): User
{
    $user = User::factory()->create();

    foreach ($permissions as $name) {
        Permission::findOrCreate($name, 'api');
    }

    $user->givePermissionTo($permissions);
    Sanctum::actingAs($user);

    return $user;
}

it('refuse la lecture du stock sans authentification', function () {
    getJson('/api/stock')->assertUnauthorized();
});

it('refuse la lecture du stock sans la permission stock.view', function () {
    actingAsStockUser(['inventaire.view']);

    getJson('/api/stock')->assertForbidden();
});

it('expose l\'état du stock avec stock.view seule, sans permission de reporting', function () {
    // Le point du module : un écran opérationnel ne doit pas dépendre de
    // `reporting.stock` ni de `reporting.global`.
    $user = actingAsStockUser(['stock.view']);

    $site = Site::factory()->create();
    rattacherAuxSites($user, $site);
    $matiere = MatierePremiere::factory()->create(['seuil_alerte_stock' => 0]);
    Stock::factory()->create([
        'matiere_premiere_id' => $matiere->id,
        'site_id' => $site->id,
        'stock_courant' => 10,
        'pmp' => 3,
    ]);

    $data = getJson('/api/stock')->assertOk()->json('data');

    expect($data['stocks'])->toHaveCount(1)
        ->and($data['stocks'][0]['matiere_premiere_id'])->toBe($matiere->id)
        ->and($data['stocks'][0]['site_id'])->toBe($site->id)
        // 10 × 3
        ->and((float) $data['stocks'][0]['valeur'])->toBe(30.0)
        ->and($data['stocks'][0]['sous_seuil'])->toBeFalse()
        ->and((float) $data['total_valorise'])->toBe(30.0);
});

it('filtre l\'état du stock par site', function () {
    $user = actingAsStockUser(['stock.view']);

    $siteA = Site::factory()->create();
    $siteB = Site::factory()->create();
    rattacherAuxSites($user, $siteA, $siteB);
    $matiere = MatierePremiere::factory()->create();

    Stock::factory()->create(['matiere_premiere_id' => $matiere->id, 'site_id' => $siteA->id, 'stock_courant' => 4, 'pmp' => 1]);
    Stock::factory()->create(['matiere_premiere_id' => $matiere->id, 'site_id' => $siteB->id, 'stock_courant' => 9, 'pmp' => 1]);

    $data = getJson("/api/stock?site_id={$siteA->id}")->assertOk()->json('data');

    expect($data['stocks'])->toHaveCount(1)
        ->and($data['stocks'][0]['site_id'])->toBe($siteA->id)
        ->and((float) $data['stocks'][0]['stock_courant'])->toBe(4.0);
});

it('marque sous_seuil les matières retombées au niveau de leur seuil', function () {
    $user = actingAsStockUser(['stock.view']);

    $site = Site::factory()->create();
    rattacherAuxSites($user, $site);
    $sousSeuil = MatierePremiere::factory()->create(['seuil_alerte_stock' => 10]);
    $auDessus = MatierePremiere::factory()->create(['seuil_alerte_stock' => 10]);
    $sansSeuil = MatierePremiere::factory()->create(['seuil_alerte_stock' => 0]);

    Stock::factory()->create(['matiere_premiere_id' => $sousSeuil->id, 'site_id' => $site->id, 'stock_courant' => 4, 'pmp' => 2]);
    Stock::factory()->create(['matiere_premiere_id' => $auDessus->id, 'site_id' => $site->id, 'stock_courant' => 50, 'pmp' => 2]);
    // Seuil nul : l'alerte est désactivée, même à 0 en stock.
    Stock::factory()->create(['matiere_premiere_id' => $sansSeuil->id, 'site_id' => $site->id, 'stock_courant' => 0, 'pmp' => 2]);

    $stocks = collect(getJson('/api/stock')->assertOk()->json('data.stocks'))
        ->keyBy('matiere_premiere_id');

    expect($stocks[$sousSeuil->id]['sous_seuil'])->toBeTrue()
        ->and($stocks[$auDessus->id]['sous_seuil'])->toBeFalse()
        ->and($stocks[$sansSeuil->id]['sous_seuil'])->toBeFalse();
});

it('ne retourne que les lignes sous seuil avec ?sous_seuil=1', function () {
    $user = actingAsStockUser(['stock.view']);

    $site = Site::factory()->create();
    rattacherAuxSites($user, $site);
    $sousSeuil = MatierePremiere::factory()->create(['seuil_alerte_stock' => 10]);
    $auDessus = MatierePremiere::factory()->create(['seuil_alerte_stock' => 10]);

    Stock::factory()->create(['matiere_premiere_id' => $sousSeuil->id, 'site_id' => $site->id, 'stock_courant' => 4, 'pmp' => 1]);
    Stock::factory()->create(['matiere_premiere_id' => $auDessus->id, 'site_id' => $site->id, 'stock_courant' => 50, 'pmp' => 1]);

    $data = getJson('/api/stock?sous_seuil=1')->assertOk()->json('data');

    expect($data['stocks'])->toHaveCount(1)
        ->and($data['stocks'][0]['matiere_premiere_id'])->toBe($sousSeuil->id);
});
