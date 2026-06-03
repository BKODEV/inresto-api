<?php

declare(strict_types=1);

use App\Models\PointDeVente;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;

use function Pest\Laravel\deleteJson;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;
use function Pest\Laravel\putJson;

uses(RefreshDatabase::class);

/**
 * @param  string[]  $permissions
 */
function actingAsSiteUser(array $permissions = []): User
{
    $user = User::factory()->create();
    foreach ($permissions as $name) {
        Permission::findOrCreate($name, 'api');
    }
    $user->givePermissionTo($permissions);
    Sanctum::actingAs($user);

    return $user;
}

// ── Périmètre (sélecteur) ──────────────────────────────────────────────────

it('liste uniquement les sites actifs de l\'utilisateur, principal en tête', function () {
    $user = User::factory()->create();
    $principal = Site::factory()->create(['nom' => 'Zeta']);
    $secondaire = Site::factory()->create(['nom' => 'Alpha']);
    $inactif = Site::factory()->inactif()->create();
    $autreSite = Site::factory()->create();

    $user->sites()->attach($principal->id, ['est_principal' => true]);
    $user->sites()->attach($secondaire->id, ['est_principal' => false]);
    $user->sites()->attach($inactif->id, ['est_principal' => false]);

    Sanctum::actingAs($user);

    $response = getJson('/api/sites/perimetre');

    $response->assertOk()
        ->assertJsonCount(2, 'data.sites')
        ->assertJsonPath('data.sites.0.id', $principal->id)
        ->assertJsonPath('data.sites.0.est_principal', true)
        ->assertJsonPath('data.sites.1.id', $secondaire->id);

    $ids = collect($response->json('data.sites'))->pluck('id');
    expect($ids)->not->toContain($inactif->id)
        ->and($ids)->not->toContain($autreSite->id);
});

it('expose les sites de l\'utilisateur dans /auth/me', function () {
    $user = User::factory()->create();
    $site = Site::factory()->create();
    $user->sites()->attach($site->id, ['est_principal' => true]);

    Sanctum::actingAs($user);

    getJson('/api/auth/me')
        ->assertOk()
        ->assertJsonCount(1, 'data.sites')
        ->assertJsonPath('data.sites.0.id', $site->id)
        ->assertJsonPath('data.sites.0.est_principal', true);
});

it('refuse le périmètre sans authentification', function () {
    getJson('/api/sites/perimetre')->assertUnauthorized();
});

// ── CRUD (gestion) ──────────────────────────────────────────────────────────

it('liste tous les sites avec la permission site.view', function () {
    Site::factory()->count(3)->create();
    actingAsSiteUser(['site.view']);

    getJson('/api/sites')
        ->assertOk()
        ->assertJsonCount(3, 'data.sites');
});

it('refuse la liste de gestion sans permission', function () {
    actingAsSiteUser([]);

    getJson('/api/sites')->assertForbidden();
});

it('crée un site', function () {
    actingAsSiteUser(['site.create']);

    postJson('/api/sites', [
        'nom' => 'Cuisine Centrale',
        'code' => 'CC-01',
    ])->assertCreated()
        ->assertJsonPath('data.nom', 'Cuisine Centrale')
        ->assertJsonPath('data.code', 'CC-01');

    expect(Site::where('code', 'CC-01')->exists())->toBeTrue();
});

it('refuse un code de site en doublon', function () {
    Site::factory()->create(['code' => 'DUP-01']);
    actingAsSiteUser(['site.create']);

    postJson('/api/sites', ['nom' => 'Autre', 'code' => 'DUP-01'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('code');
});

it('met à jour un site', function () {
    $site = Site::factory()->create(['nom' => 'Ancien']);
    actingAsSiteUser(['site.update']);

    putJson("/api/sites/{$site->id}", ['nom' => 'Nouveau'])
        ->assertOk()
        ->assertJsonPath('data.nom', 'Nouveau');
});

it('supprime un site sans dépendances', function () {
    $site = Site::factory()->create();
    actingAsSiteUser(['site.delete']);

    deleteJson("/api/sites/{$site->id}")->assertOk();

    expect(Site::find($site->id))->toBeNull();
});

it('refuse de supprimer un site portant des points de vente', function () {
    $site = Site::factory()->create();
    PointDeVente::factory()->create(['site_id' => $site->id]);
    actingAsSiteUser(['site.delete']);

    deleteJson("/api/sites/{$site->id}")->assertStatus(409);

    expect(Site::find($site->id))->not->toBeNull();
});
