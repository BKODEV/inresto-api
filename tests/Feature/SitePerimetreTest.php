<?php

declare(strict_types=1);

use App\Models\Budget;
use App\Models\Inventaire;
use App\Models\MatierePremiere;
use App\Models\MouvementStock;
use App\Models\Reception;
use App\Models\Site;
use App\Models\Stock;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;

use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

uses(RefreshDatabase::class);

/**
 * Le pivot `user_site` est une frontière, pas une commodité d'affichage : stock,
 * PMP et budgets sont propres à chaque site et aucun transfert inter-sites
 * n'existe. Une écriture posée sur le mauvais site ne se rattrape donc que par
 * une correction d'inventaire des deux côtés.
 *
 * Ces tests verrouillent le bornage sur les deux versants — lecture (index et
 * accès direct par identifiant) et écriture — et l'exemption du super-admin.
 */

/**
 * Utilisateur muni des permissions données, rattaché au seul site fourni.
 *
 * @param  string[]  $permissions
 */
function actingAsPerimetreUser(array $permissions, Site $site): User
{
    $user = User::factory()->create();

    foreach ($permissions as $name) {
        Permission::findOrCreate($name, 'api');
    }
    $user->givePermissionTo($permissions);
    $user->sites()->sync([$site->id]);

    Sanctum::actingAs($user);

    return $user;
}

/** Les cinq index bornés par le périmètre, avec la permission qui les ouvre. */
dataset('index bornés par le site', [
    'stock' => ['/api/stock', 'stock.view'],
    'mouvements' => ['/api/stock/mouvements', 'stock.view'],
    'inventaires' => ['/api/inventaires', 'inventaire.view'],
    'receptions' => ['/api/receptions', 'reception.view'],
    'budgets' => ['/api/budgets', 'budget.view'],
]);

// ───────────────────────── lecture : index ─────────────────────────

it('refuse un site_id hors périmètre (403)', function (string $url, string $permission) {
    $sien = Site::factory()->create();
    $autre = Site::factory()->create();
    actingAsPerimetreUser([$permission], $sien);

    getJson("{$url}?site_id={$autre->id}")->assertForbidden();
})->with('index bornés par le site');

it('accepte un site_id du périmètre (200)', function (string $url, string $permission) {
    $sien = Site::factory()->create();
    actingAsPerimetreUser([$permission], $sien);

    getJson("{$url}?site_id={$sien->id}")->assertOk();
})->with('index bornés par le site');

it('ne remonte que les inventaires du périmètre', function () {
    $sien = Site::factory()->create();
    $autre = Site::factory()->create();
    actingAsPerimetreUser(['inventaire.view'], $sien);

    $visible = Inventaire::factory()->create(['site_id' => $sien->id]);
    $invisible = Inventaire::factory()->create(['site_id' => $autre->id]);

    $ids = collect(getJson('/api/inventaires')->assertOk()->json('data.inventaires'))->pluck('id');

    expect($ids)->toContain($visible->id)
        ->and($ids)->not->toContain($invisible->id);
});

it('ne remonte que le stock du périmètre', function () {
    $sien = Site::factory()->create();
    $autre = Site::factory()->create();
    actingAsPerimetreUser(['stock.view'], $sien);

    $matiere = MatierePremiere::factory()->create();
    Stock::factory()->create(['matiere_premiere_id' => $matiere->id, 'site_id' => $sien->id]);
    Stock::factory()->create(['matiere_premiere_id' => $matiere->id, 'site_id' => $autre->id]);

    $stocks = getJson('/api/stock')->assertOk()->json('data.stocks');

    expect($stocks)->toHaveCount(1)
        ->and($stocks[0]['site_id'])->toBe($sien->id);
});

it('ne remonte que les mouvements du périmètre', function () {
    $sien = Site::factory()->create();
    $autre = Site::factory()->create();
    actingAsPerimetreUser(['stock.view'], $sien);

    $matiere = MatierePremiere::factory()->create();
    MouvementStock::factory()->create(['matiere_premiere_id' => $matiere->id, 'site_id' => $sien->id]);
    MouvementStock::factory()->create(['matiere_premiere_id' => $matiere->id, 'site_id' => $autre->id]);

    $mouvements = getJson('/api/stock/mouvements')->assertOk()->json('data.mouvements');

    expect($mouvements)->toHaveCount(1)
        ->and($mouvements[0]['site_id'])->toBe($sien->id);
});

// ───────────────────────── lecture : accès direct ─────────────────────────

it('refuse l\'accès direct à un inventaire hors périmètre (403)', function () {
    $autre = Site::factory()->create();
    actingAsPerimetreUser(['inventaire.view'], Site::factory()->create());

    $inventaire = Inventaire::factory()->create(['site_id' => $autre->id]);

    getJson("/api/inventaires/{$inventaire->id}")->assertForbidden();
});

it('refuse l\'accès direct à une réception hors périmètre (403)', function () {
    $autre = Site::factory()->create();
    actingAsPerimetreUser(['reception.view'], Site::factory()->create());

    $reception = Reception::factory()->create(['site_id' => $autre->id]);

    getJson("/api/receptions/{$reception->id}")->assertForbidden();
});

it('refuse l\'accès direct à un budget hors périmètre (403)', function () {
    $autre = Site::factory()->create();
    actingAsPerimetreUser(['budget.view'], Site::factory()->create());

    $budget = Budget::factory()->create(['site_id' => $autre->id]);

    getJson("/api/budgets/{$budget->id}")->assertForbidden();
});

// ───────────────────────── écriture ─────────────────────────

it('refuse une réception sur un site hors périmètre (422)', function () {
    $autre = Site::factory()->create();
    actingAsPerimetreUser(['reception.create'], Site::factory()->create());

    postJson('/api/receptions', ['site_id' => $autre->id])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('site_id');
});

it('refuse un inventaire sur un site hors périmètre (422)', function () {
    $autre = Site::factory()->create();
    actingAsPerimetreUser(['inventaire.create'], Site::factory()->create());

    postJson('/api/inventaires', ['site_id' => $autre->id])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('site_id');
});

it('refuse un budget sur un site hors périmètre (422)', function () {
    $autre = Site::factory()->create();
    actingAsPerimetreUser(['budget.create'], Site::factory()->create());

    postJson('/api/budgets', [
        'site_id' => $autre->id,
        'mois' => '2026-06-01',
        'montant_alloue' => 1000,
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('site_id');
});

// ───────────────────────── super-admin ─────────────────────────

it('n\'oppose aucun périmètre au super-admin', function () {
    seedRbac();
    $sien = Site::factory()->create();
    $autre = Site::factory()->create();
    actingAsSuperAdmin([$sien->id]);

    getJson("/api/stock?site_id={$autre->id}")->assertOk();
    getJson("/api/inventaires?site_id={$autre->id}")->assertOk();

    postJson('/api/inventaires', ['site_id' => $autre->id])->assertCreated();
});
