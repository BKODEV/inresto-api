<?php

declare(strict_types=1);

use App\Models\CategorieProduit;
use App\Models\FicheTechnique;
use App\Models\Produit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;

use function Pest\Laravel\deleteJson;
use function Pest\Laravel\getJson;
use function Pest\Laravel\patchJson;
use function Pest\Laravel\postJson;

uses(RefreshDatabase::class);

/**
 * @param  string[]  $permissions
 */
function actingAsProduitUser(array $permissions = []): User
{
    $user = User::factory()->create();
    foreach ($permissions as $name) {
        Permission::findOrCreate($name, 'api');
    }
    $user->givePermissionTo($permissions);
    Sanctum::actingAs($user);

    return $user;
}

// ───────────────────────── CRUD ─────────────────────────

it('crée un produit', function () {
    actingAsProduitUser(['produit.create']);
    $categorie = CategorieProduit::factory()->create();

    postJson('/api/produits', [
        'categorie_id' => $categorie->id,
        'libelle' => 'Tarte aux pommes',
        'description' => 'Maison',
        'est_menu' => false,
    ])
        ->assertCreated()
        ->assertJsonPath('data.libelle', 'Tarte aux pommes')
        ->assertJsonPath('data.statut', 'actif');

    expect(Produit::where('libelle', 'Tarte aux pommes')->exists())->toBeTrue();
});

it('affiche un produit avec sa catégorie', function () {
    actingAsProduitUser(['produit.view']);
    $produit = Produit::factory()->create();

    getJson("/api/produits/{$produit->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $produit->id)
        ->assertJsonPath('data.categorie.id', $produit->categorie_id);
});

it('modifie un produit', function () {
    actingAsProduitUser(['produit.update']);
    $produit = Produit::factory()->create();

    patchJson("/api/produits/{$produit->id}", ['libelle' => 'Nouveau nom'])
        ->assertOk()
        ->assertJsonPath('data.libelle', 'Nouveau nom');

    expect($produit->fresh()->libelle)->toBe('Nouveau nom');
});

it('supprime (soft) un produit sans fiche active (200)', function () {
    actingAsProduitUser(['produit.delete']);
    $produit = Produit::factory()->create();

    deleteJson("/api/produits/{$produit->id}")->assertOk();

    expect(Produit::find($produit->id))->toBeNull()
        ->and(Produit::withTrashed()->find($produit->id))->not->toBeNull();
});

it('refuse de supprimer un produit référencé dans une fiche active (409)', function () {
    actingAsProduitUser(['produit.delete']);
    $produit = Produit::factory()->create();
    FicheTechnique::factory()->create(['produit_id' => $produit->id]);

    deleteJson("/api/produits/{$produit->id}")->assertStatus(409);

    expect(Produit::find($produit->id))->not->toBeNull();
});

// ───────────────────────── filtres index ─────────────────────────

it('filtre l\'index par ?categorie_id et ?statut', function () {
    actingAsProduitUser(['produit.view']);
    $categorieA = CategorieProduit::factory()->create();
    $categorieB = CategorieProduit::factory()->create();
    Produit::factory()->create(['categorie_id' => $categorieA->id]);
    Produit::factory()->inactif()->create(['categorie_id' => $categorieA->id]);
    Produit::factory()->create(['categorie_id' => $categorieB->id]);

    getJson("/api/produits?categorie_id={$categorieA->id}")
        ->assertOk()
        ->assertJsonCount(2, 'data.produits');

    getJson("/api/produits?categorie_id={$categorieA->id}&statut=actif")
        ->assertOk()
        ->assertJsonCount(1, 'data.produits');
});

// ───────────────────────── permissions ─────────────────────────

it('refuse l\'accès sans authentification', function () {
    getJson('/api/produits')->assertUnauthorized();
});

it('refuse la création sans la permission', function () {
    actingAsProduitUser([]);

    postJson('/api/produits', [
        'categorie_id' => CategorieProduit::factory()->create()->id,
        'libelle' => 'X',
    ])->assertForbidden();
});
