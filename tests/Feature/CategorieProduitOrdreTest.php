<?php

declare(strict_types=1);

use App\Models\CategorieProduit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;

use function Pest\Laravel\patchJson;
use function Pest\Laravel\postJson;

uses(RefreshDatabase::class);

/**
 * @param  string[]  $permissions
 */
function actingAsCategorieUser(array $permissions = []): User
{
    $user = User::factory()->create();
    foreach ($permissions as $name) {
        Permission::findOrCreate($name, 'api');
    }
    $user->givePermissionTo($permissions);
    Sanctum::actingAs($user);

    return $user;
}

it('place les nouvelles catégories en fin de liste (1 puis 2) sans ordre fourni', function () {
    actingAsCategorieUser(['categorie-produit.create']);

    postJson('/api/parametres/categories-produit', ['libelle' => 'Entrées'])
        ->assertCreated()
        ->assertJsonPath('data.ordre', 1);

    postJson('/api/parametres/categories-produit', ['libelle' => 'Plats'])
        ->assertCreated()
        ->assertJsonPath('data.ordre', 2);
});

it('décale les catégories existantes quand on en insère une à un ordre occupé', function () {
    actingAsCategorieUser(['categorie-produit.create']);
    $existante = CategorieProduit::factory()->create(['ordre' => 1]);

    postJson('/api/parametres/categories-produit', ['libelle' => 'Apéritifs', 'ordre' => 1])
        ->assertCreated()
        ->assertJsonPath('data.ordre', 1);

    expect($existante->fresh()->ordre)->toBe(2);
});

it('réinsère une catégorie et décale ses voisines quand on change son ordre', function () {
    actingAsCategorieUser(['categorie-produit.update']);
    $premiere = CategorieProduit::factory()->create(['ordre' => 1]);
    $seconde = CategorieProduit::factory()->create(['ordre' => 2]);

    patchJson("/api/parametres/categories-produit/{$seconde->id}", ['ordre' => 1])
        ->assertOk()
        ->assertJsonPath('data.ordre', 1);

    expect($premiere->fresh()->ordre)->toBe(2);
    expect($seconde->fresh()->ordre)->toBe(1);
});
