<?php

declare(strict_types=1);

use App\Models\Caisse;
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

it('liste uniquement les sites actifs de l\'utilisateur, triés par nom', function () {
    $user = User::factory()->create();
    $siteZeta = Site::factory()->create(['nom' => 'Zeta']);
    $siteAlpha = Site::factory()->create(['nom' => 'Alpha']);
    $inactif = Site::factory()->inactif()->create();
    $autreSite = Site::factory()->create();

    $user->sites()->attach($siteZeta->id);
    $user->sites()->attach($siteAlpha->id);
    $user->sites()->attach($inactif->id);

    Sanctum::actingAs($user);

    $response = getJson('/api/sites/perimetre');

    $response->assertOk()
        ->assertJsonCount(2, 'data.sites')
        ->assertJsonPath('data.sites.0.id', $siteAlpha->id)
        ->assertJsonPath('data.sites.1.id', $siteZeta->id);

    $ids = collect($response->json('data.sites'))->pluck('id');
    expect($ids)->not->toContain($inactif->id)
        ->and($ids)->not->toContain($autreSite->id);
});

it('expose les sites de l\'utilisateur dans /auth/me', function () {
    $user = User::factory()->create();
    $site = Site::factory()->create();
    $user->sites()->attach($site->id);

    Sanctum::actingAs($user);

    getJson('/api/auth/me')
        ->assertOk()
        ->assertJsonCount(1, 'data.sites')
        ->assertJsonPath('data.sites.0.id', $site->id);
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

it('expose le nombre de points de vente dans la liste de gestion', function () {
    $site = Site::factory()->create();
    PointDeVente::factory()->count(2)->create(['site_id' => $site->id]);
    actingAsSiteUser(['site.view']);

    getJson('/api/sites')
        ->assertOk()
        ->assertJsonPath('data.sites.0.points_de_vente_count', 2);
});

it('retourne le détail d\'un site avec ses points de vente et leur nombre de caisses', function () {
    $site = Site::factory()->create();
    $lounge = PointDeVente::factory()->create(['site_id' => $site->id, 'nom' => 'Lounge']);
    PointDeVente::factory()->create(['site_id' => $site->id, 'nom' => 'Restaurant']);
    Caisse::factory()->count(2)->create(['point_de_vente_id' => $lounge->id]);
    actingAsSiteUser(['site.view']);

    getJson("/api/sites/{$site->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $site->id)
        ->assertJsonPath('data.points_de_vente_count', 2)
        ->assertJsonCount(2, 'data.points_de_vente')
        ->assertJsonPath('data.points_de_vente.0.nom', 'Lounge')
        ->assertJsonPath('data.points_de_vente.0.caisses_count', 2)
        ->assertJsonPath('data.points_de_vente.1.caisses_count', 0);
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
