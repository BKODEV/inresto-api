<?php

declare(strict_types=1);

use App\Models\ParametreEtablissement;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;

use function Pest\Laravel\getJson;
use function Pest\Laravel\putJson;

uses(RefreshDatabase::class);

/**
 * @param  string[]  $permissions
 */
function actingWithParamPerms(array $permissions = []): User
{
    $user = User::factory()->create();

    foreach ($permissions as $name) {
        Permission::findOrCreate($name, 'api');
    }

    $user->givePermissionTo($permissions);
    Sanctum::actingAs($user);

    return $user;
}

it('crée puis met à jour la même fiche (upsert, pas de doublon)', function () {
    actingWithParamPerms(['parametre_etablissement.update']);
    $site = Site::factory()->create();

    putJson("/api/sites/{$site->id}/parametres", [
        'nom_commercial' => 'Resto A',
        'devise' => 'XOF',
    ])->assertOk()->assertJsonPath('data.nom_commercial', 'Resto A');

    putJson("/api/sites/{$site->id}/parametres", [
        'nom_commercial' => 'Resto B',
        'devise' => 'EUR',
    ])->assertOk()->assertJsonPath('data.nom_commercial', 'Resto B');

    expect(ParametreEtablissement::where('site_id', $site->id)->count())->toBe(1);
    expect(ParametreEtablissement::where('site_id', $site->id)->first()->devise)->toBe('EUR');
});

it('valide les champs requis', function () {
    actingWithParamPerms(['parametre_etablissement.update']);
    $site = Site::factory()->create();

    putJson("/api/sites/{$site->id}/parametres", [
        'email' => 'pas-un-email',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['nom_commercial', 'devise', 'email']);
});

it('renvoie 404 quand la fiche n\'existe pas encore', function () {
    actingWithParamPerms(['parametre_etablissement.view']);
    $site = Site::factory()->create();

    getJson("/api/sites/{$site->id}/parametres")->assertNotFound();
});

it('expose la fiche existante', function () {
    actingWithParamPerms(['parametre_etablissement.view']);
    $site = Site::factory()->create();
    ParametreEtablissement::factory()->create(['site_id' => $site->id, 'nom_commercial' => 'Chez Test']);

    getJson("/api/sites/{$site->id}/parametres")
        ->assertOk()
        ->assertJsonPath('data.nom_commercial', 'Chez Test');
});

it('refuse l\'upsert sans la permission update', function () {
    actingWithParamPerms([]);
    $site = Site::factory()->create();

    putJson("/api/sites/{$site->id}/parametres", [
        'nom_commercial' => 'X',
        'devise' => 'XOF',
    ])->assertForbidden();
});

it('refuse la lecture sans la permission view', function () {
    actingWithParamPerms([]);
    $site = Site::factory()->create();
    ParametreEtablissement::factory()->create(['site_id' => $site->id]);

    getJson("/api/sites/{$site->id}/parametres")->assertForbidden();
});
