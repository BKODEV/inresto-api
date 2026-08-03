<?php

declare(strict_types=1);

use App\Enums\MouvementType;
use App\Models\MatierePremiere;
use App\Models\MouvementStock;
use App\Models\Site;
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
function actingAsMouvementUser(array $permissions = []): User
{
    $user = User::factory()->create();

    foreach ($permissions as $name) {
        Permission::findOrCreate($name, 'api');
    }

    $user->givePermissionTo($permissions);
    Sanctum::actingAs($user);

    return $user;
}

it('refuse le journal des mouvements sans authentification', function () {
    getJson('/api/stock/mouvements')->assertUnauthorized();
});

it('refuse le journal des mouvements sans la permission stock.view', function () {
    actingAsMouvementUser(['inventaire.view']);

    getJson('/api/stock/mouvements')->assertForbidden();
});

it('expose le journal avec le sens et la valeur au pmp figé', function () {
    $user = actingAsMouvementUser(['stock.view']);

    $site = Site::factory()->create();
    rattacherAuxSites($user, $site);
    $matiere = MatierePremiere::factory()->create();
    MouvementStock::factory()->create([
        'matiere_premiere_id' => $matiere->id,
        'site_id' => $site->id,
        'type' => MouvementType::Perte->value,
        'quantite' => 3,
        'pmp_au_moment' => 4,
        'date_mouvement' => now(),
    ]);

    $data = getJson('/api/stock/mouvements')->assertOk()->json('data');

    expect($data['mouvements'])->toHaveCount(1)
        ->and($data['mouvements'][0]['type'])->toBe('perte')
        ->and($data['mouvements'][0]['sens'])->toBe('sortie')
        // 3 × 4, valorisé au PMP figé et non au PMP courant.
        ->and((float) $data['mouvements'][0]['valeur'])->toBe(12.0)
        ->and($data['pagination']['total'])->toBe(1);
});

it('trie du plus récent au plus ancien', function () {
    $user = actingAsMouvementUser(['stock.view']);

    $site = Site::factory()->create();
    rattacherAuxSites($user, $site);
    $matiere = MatierePremiere::factory()->create();

    $ancien = MouvementStock::factory()->create([
        'matiere_premiere_id' => $matiere->id,
        'site_id' => $site->id,
        'date_mouvement' => now()->subDays(3),
    ]);
    $recent = MouvementStock::factory()->create([
        'matiere_premiere_id' => $matiere->id,
        'site_id' => $site->id,
        'date_mouvement' => now(),
    ]);

    $ids = collect(getJson('/api/stock/mouvements')->assertOk()->json('data.mouvements'))
        ->pluck('id')
        ->all();

    expect($ids)->toBe([$recent->id, $ancien->id]);
});

it('filtre par site, matière et type', function () {
    $user = actingAsMouvementUser(['stock.view']);

    $siteA = Site::factory()->create();
    $siteB = Site::factory()->create();
    rattacherAuxSites($user, $siteA, $siteB);
    $matiereA = MatierePremiere::factory()->create();
    $matiereB = MatierePremiere::factory()->create();

    $cible = MouvementStock::factory()->create([
        'matiere_premiere_id' => $matiereA->id,
        'site_id' => $siteA->id,
        'type' => MouvementType::Casse->value,
    ]);
    MouvementStock::factory()->create(['matiere_premiere_id' => $matiereA->id, 'site_id' => $siteB->id, 'type' => MouvementType::Casse->value]);
    MouvementStock::factory()->create(['matiere_premiere_id' => $matiereB->id, 'site_id' => $siteA->id, 'type' => MouvementType::Casse->value]);
    MouvementStock::factory()->create(['matiere_premiere_id' => $matiereA->id, 'site_id' => $siteA->id, 'type' => MouvementType::Perte->value]);

    $data = getJson("/api/stock/mouvements?site_id={$siteA->id}&matiere_premiere_id={$matiereA->id}&type=casse")
        ->assertOk()
        ->json('data.mouvements');

    expect($data)->toHaveCount(1)
        ->and($data[0]['id'])->toBe($cible->id);
});

it('inclut les mouvements du dernier jour de la période, quelle que soit l\'heure', function () {
    // `date_mouvement` est un timestamp : borner sur la chaîne « Y-m-d »
    // exclurait tout ce qui suit minuit le dernier jour.
    $user = actingAsMouvementUser(['stock.view']);

    $site = Site::factory()->create();
    rattacherAuxSites($user, $site);
    $matiere = MatierePremiere::factory()->create();

    MouvementStock::factory()->create([
        'matiere_premiere_id' => $matiere->id,
        'site_id' => $site->id,
        'date_mouvement' => '2026-03-31 18:45:00',
    ]);
    MouvementStock::factory()->create([
        'matiere_premiere_id' => $matiere->id,
        'site_id' => $site->id,
        'date_mouvement' => '2026-04-01 09:00:00',
    ]);

    $data = getJson('/api/stock/mouvements?date_debut=2026-03-01&date_fin=2026-03-31')
        ->assertOk()
        ->json('data.mouvements');

    expect($data)->toHaveCount(1);
});

it('rejette un type inconnu et une période inversée', function () {
    actingAsMouvementUser(['stock.view']);

    getJson('/api/stock/mouvements?type=nimporte_quoi')
        ->assertUnprocessable()
        ->assertJsonValidationErrors('type');

    getJson('/api/stock/mouvements?date_debut=2026-03-31&date_fin=2026-03-01')
        ->assertUnprocessable()
        ->assertJsonValidationErrors('date_fin');
});

it('expose l\'origine du mouvement via son alias morphologique', function () {
    $user = actingAsMouvementUser(['stock.view']);

    $site = Site::factory()->create();
    rattacherAuxSites($user, $site);
    $matiere = MatierePremiere::factory()->create();
    MouvementStock::factory()->create([
        'matiere_premiere_id' => $matiere->id,
        'site_id' => $site->id,
        'reference_type' => 'lignes_reception',
        'reference_id' => 42,
    ]);

    $data = getJson('/api/stock/mouvements')->assertOk()->json('data.mouvements.0');

    expect($data['reference_type'])->toBe('lignes_reception')
        ->and($data['reference_id'])->toBe(42);
});
