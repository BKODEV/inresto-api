<?php

declare(strict_types=1);

use App\Models\ParametreEtablissement;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;

use function Pest\Laravel\deleteJson;
use function Pest\Laravel\postJson;

uses(RefreshDatabase::class);

/**
 * @param  string[]  $permissions
 */
function actingWithLogoPerms(array $permissions = ['parametre_etablissement.update']): User
{
    $user = User::factory()->create();

    foreach ($permissions as $name) {
        Permission::findOrCreate($name, 'api');
    }

    $user->givePermissionTo($permissions);
    Sanctum::actingAs($user);

    return $user;
}

it('stocke le logo et met à jour logo_path', function () {
    Storage::fake('public');
    actingWithLogoPerms();
    $site = Site::factory()->create();
    ParametreEtablissement::factory()->create(['site_id' => $site->id, 'logo_path' => null]);

    postJson("/api/sites/{$site->id}/parametres/logo", [
        'logo' => UploadedFile::fake()->image('logo.png', 200, 200),
    ])->assertOk();

    $parametre = ParametreEtablissement::where('site_id', $site->id)->first();
    expect($parametre->logo_path)->not->toBeNull();
    Storage::disk('public')->assertExists($parametre->logo_path);
});

it('refuse un fichier trop volumineux', function () {
    Storage::fake('public');
    actingWithLogoPerms();
    $site = Site::factory()->create();
    ParametreEtablissement::factory()->create(['site_id' => $site->id]);

    postJson("/api/sites/{$site->id}/parametres/logo", [
        'logo' => UploadedFile::fake()->image('logo.png')->size(3000),
    ])->assertUnprocessable()->assertJsonValidationErrors(['logo']);
});

it('refuse un format non autorisé', function () {
    Storage::fake('public');
    actingWithLogoPerms();
    $site = Site::factory()->create();
    ParametreEtablissement::factory()->create(['site_id' => $site->id]);

    postJson("/api/sites/{$site->id}/parametres/logo", [
        'logo' => UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf'),
    ])->assertUnprocessable()->assertJsonValidationErrors(['logo']);
});

it('renvoie 404 si aucune fiche n\'existe pour le site', function () {
    Storage::fake('public');
    actingWithLogoPerms();
    $site = Site::factory()->create();

    postJson("/api/sites/{$site->id}/parametres/logo", [
        'logo' => UploadedFile::fake()->image('logo.png'),
    ])->assertNotFound();
});

it('supprime le logo et son fichier', function () {
    Storage::fake('public');
    actingWithLogoPerms();
    $site = Site::factory()->create();
    $parametre = ParametreEtablissement::factory()->create(['site_id' => $site->id]);

    postJson("/api/sites/{$site->id}/parametres/logo", [
        'logo' => UploadedFile::fake()->image('logo.png'),
    ])->assertOk();
    $chemin = $parametre->fresh()->logo_path;

    deleteJson("/api/sites/{$site->id}/parametres/logo")->assertOk();

    expect($parametre->fresh()->logo_path)->toBeNull();
    Storage::disk('public')->assertMissing($chemin);
});

it('refuse l\'upload sans la permission update', function () {
    Storage::fake('public');
    actingWithLogoPerms([]);
    $site = Site::factory()->create();
    ParametreEtablissement::factory()->create(['site_id' => $site->id]);

    postJson("/api/sites/{$site->id}/parametres/logo", [
        'logo' => UploadedFile::fake()->image('logo.png'),
    ])->assertForbidden();
});
