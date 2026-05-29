<?php

declare(strict_types=1);

use App\Models\Fournisseur;
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
 * Authentifie un utilisateur disposant des permissions fournies.
 *
 * @param  string[]  $permissions
 */
function actingAsUserAvecPermissions(array $permissions = []): User
{
    $user = User::factory()->create();

    foreach ($permissions as $name) {
        Permission::findOrCreate($name, 'api');
    }

    $user->givePermissionTo($permissions);

    Sanctum::actingAs($user);

    return $user;
}

it('refuse l\'accès aux utilisateurs non authentifiés', function () {
    getJson('/api/fournisseurs')->assertUnauthorized();
});

it('refuse l\'accès sans la permission requise', function () {
    actingAsUserAvecPermissions();

    getJson('/api/fournisseurs')->assertForbidden();
});

it('liste les fournisseurs avec pagination', function () {
    actingAsUserAvecPermissions(['fournisseur.view']);
    Fournisseur::factory()->count(3)->create();

    getJson('/api/fournisseurs')
        ->assertOk()
        ->assertJsonPath('status', 'success')
        ->assertJsonPath('data.pagination.total', 3)
        ->assertJsonCount(3, 'data.fournisseurs');
});

it('recherche les fournisseurs par nom', function () {
    actingAsUserAvecPermissions(['fournisseur.view']);
    Fournisseur::factory()->create(['nom' => 'Boucherie Martin']);
    Fournisseur::factory()->create(['nom' => 'Primeur Dupont']);

    getJson('/api/fournisseurs?nom=Martin')
        ->assertOk()
        ->assertJsonCount(1, 'data.fournisseurs')
        ->assertJsonPath('data.fournisseurs.0.nom', 'Boucherie Martin');
});

it('filtre les fournisseurs par statut', function () {
    actingAsUserAvecPermissions(['fournisseur.view']);
    Fournisseur::factory()->create();
    Fournisseur::factory()->inactif()->create();

    getJson('/api/fournisseurs?statut=inactif')
        ->assertOk()
        ->assertJsonCount(1, 'data.fournisseurs')
        ->assertJsonPath('data.fournisseurs.0.statut', 'inactif');
});

it('crée un fournisseur', function () {
    actingAsUserAvecPermissions(['fournisseur.create']);

    postJson('/api/fournisseurs', [
        'nom' => 'Grossiste Légumes',
        'email' => 'contact@grossiste.fr',
        'delai_livraison_jours' => 3,
    ])
        ->assertCreated()
        ->assertJsonPath('data.nom', 'Grossiste Légumes')
        ->assertJsonPath('data.statut', 'actif');

    $this->assertDatabaseHas('fournisseurs', ['nom' => 'Grossiste Légumes']);
});

it('exige le nom à la création', function () {
    actingAsUserAvecPermissions(['fournisseur.create']);

    postJson('/api/fournisseurs', [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('nom');
});

it('rejette un email invalide', function () {
    actingAsUserAvecPermissions(['fournisseur.create']);

    postJson('/api/fournisseurs', ['nom' => 'Test', 'email' => 'pas-un-email'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('email');
});

it('rejette un délai de livraison non positif', function () {
    actingAsUserAvecPermissions(['fournisseur.create']);

    postJson('/api/fournisseurs', ['nom' => 'Test', 'delai_livraison_jours' => 0])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('delai_livraison_jours');
});

it('affiche un fournisseur', function () {
    actingAsUserAvecPermissions(['fournisseur.view']);
    $fournisseur = Fournisseur::factory()->create();

    getJson("/api/fournisseurs/{$fournisseur->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $fournisseur->id);
});

it('met à jour un fournisseur', function () {
    actingAsUserAvecPermissions(['fournisseur.update']);
    $fournisseur = Fournisseur::factory()->create(['nom' => 'Ancien nom']);

    putJson("/api/fournisseurs/{$fournisseur->id}", ['nom' => 'Nouveau nom'])
        ->assertOk()
        ->assertJsonPath('data.nom', 'Nouveau nom');

    $this->assertDatabaseHas('fournisseurs', ['id' => $fournisseur->id, 'nom' => 'Nouveau nom']);
});

it('supprime un fournisseur en soft delete', function () {
    actingAsUserAvecPermissions(['fournisseur.delete']);
    $fournisseur = Fournisseur::factory()->create();

    deleteJson("/api/fournisseurs/{$fournisseur->id}")
        ->assertOk()
        ->assertJsonPath('status', 'success');

    $this->assertSoftDeleted($fournisseur);
});
