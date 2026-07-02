<?php

declare(strict_types=1);

use App\Models\MatierePremiere;
use App\Models\Site;
use App\Models\Stock;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;

use function Pest\Laravel\getJson;

uses(RefreshDatabase::class);

/** Crée une ligne de stock valorisée sur un site. */
function stockValorise(int $siteId): void
{
    Stock::factory()->create([
        'matiere_premiere_id' => MatierePremiere::factory()->create()->id,
        'site_id' => $siteId,
        'stock_courant' => 10,
        'pmp' => 2,
    ]);
}

it('refuse chaque famille sans la permission dédiée', function (string $url, string $permissionManquante) {
    // L'utilisateur a TOUTES les permissions reporting sauf celle requise.
    $autres = array_diff(
        ['reporting.food_cost', 'reporting.ventes', 'reporting.stock', 'reporting.budget'],
        [$permissionManquante]
    );
    actingAsReportingUser([...$autres, 'reporting.global']);

    getJson($url)->assertForbidden();
})->with([
    'food cost' => ['/api/reporting/food-cost/synthese', 'reporting.food_cost'],
    'ventes' => ['/api/reporting/ventes/points-de-vente', 'reporting.ventes'],
    'stock' => ['/api/reporting/stock/valorisation', 'reporting.stock'],
    'budget' => ['/api/reporting/budget/consommation', 'reporting.budget'],
]);

it('autorise le super-admin sans permission reporting explicite', function () {
    $role = Role::findOrCreate('super-admin', 'api');
    $user = User::factory()->create();
    $user->assignRole($role);
    Sanctum::actingAs($user);

    // Aucune permission reporting.* assignée : passe quand même (bypass super-admin)
    // et agrège tous les sites (périmètre global).
    $siteA = Site::factory()->create();
    stockValorise($siteA->id);

    $data = getJson('/api/reporting/stock/valorisation')->assertOk()->json('data');
    expect($data['sites'])->toHaveCount(1);
});

it('restreint au site demandé via ?site_id', function () {
    $siteA = Site::factory()->create();
    $siteB = Site::factory()->create();
    actingAsReportingUser(['reporting.stock', 'reporting.global']);

    stockValorise($siteA->id);
    stockValorise($siteB->id);

    $data = getJson("/api/reporting/stock/valorisation?site_id={$siteA->id}")->assertOk()->json('data');

    expect($data['sites'])->toHaveCount(1);
    expect($data['sites'][0]['site_id'])->toBe($siteA->id);
});

it('limite un utilisateur non-global à ses sites (user_site)', function () {
    $siteAutorise = Site::factory()->create();
    $siteInterdit = Site::factory()->create();

    // Pas de reporting.global ; rattaché uniquement au site autorisé.
    actingAsReportingUser(['reporting.stock'], [$siteAutorise->id]);

    stockValorise($siteAutorise->id);
    stockValorise($siteInterdit->id);

    // Sans filtre : seul son site remonte.
    $data = getJson('/api/reporting/stock/valorisation')->assertOk()->json('data');
    expect($data['sites'])->toHaveCount(1)
        ->and($data['sites'][0]['site_id'])->toBe($siteAutorise->id);

    // Demande explicite d'un site hors périmètre : refusée.
    getJson("/api/reporting/stock/valorisation?site_id={$siteInterdit->id}")->assertForbidden();
});
