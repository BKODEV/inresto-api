<?php

declare(strict_types=1);

use App\Models\Carte;
use App\Models\CarteProduit;
use App\Models\FicheTechnique;
use App\Models\Produit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;

use function Pest\Laravel\deleteJson;
use function Pest\Laravel\patchJson;
use function Pest\Laravel\postJson;

uses(RefreshDatabase::class);

/**
 * @param  string[]  $permissions
 */
function actingAsCarteUser(array $permissions = []): User
{
    $user = User::factory()->create();
    foreach ($permissions as $name) {
        Permission::findOrCreate($name, 'api');
    }
    $user->givePermissionTo($permissions);
    Sanctum::actingAs($user);

    return $user;
}

// ───────────────────────── ajout ─────────────────────────

it('ajoute un produit à une carte', function () {
    actingAsCarteUser(['carte_produit.create']);
    $carte = Carte::factory()->create();
    $produit = Produit::factory()->create();

    postJson("/api/cartes/{$carte->id}/produits", [
        'produit_id' => $produit->id,
        'prix_vente' => 12.50,
    ])
        ->assertCreated()
        ->assertJsonPath('data.prix_vente', '12.50')
        ->assertJsonPath('data.produit.id', $produit->id);

    expect(CarteProduit::where('carte_id', $carte->id)->where('produit_id', $produit->id)->exists())->toBeTrue();
});

it('refuse un doublon de produit sur la même carte (422)', function () {
    actingAsCarteUser(['carte_produit.create']);
    $carte = Carte::factory()->create();
    $produit = Produit::factory()->create();
    CarteProduit::factory()->create(['carte_id' => $carte->id, 'produit_id' => $produit->id]);

    postJson("/api/cartes/{$carte->id}/produits", [
        'produit_id' => $produit->id,
        'prix_vente' => 9,
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('produit_id');
});

it('refuse l\'ajout d\'un produit inactif (422)', function () {
    actingAsCarteUser(['carte_produit.create']);
    $carte = Carte::factory()->create();
    $produit = Produit::factory()->inactif()->create();

    postJson("/api/cartes/{$carte->id}/produits", [
        'produit_id' => $produit->id,
        'prix_vente' => 9,
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('produit_id');
});

// ───────────────────────── modification ─────────────────────────

it('modifie le prix de vente sur une carte active', function () {
    actingAsCarteUser(['carte_produit.update']);
    $carte = Carte::factory()->active()->create();
    $ligne = CarteProduit::factory()->create(['carte_id' => $carte->id]);

    patchJson("/api/cartes/{$carte->id}/produits/{$ligne->id}", ['prix_vente' => 18])
        ->assertOk()
        ->assertJsonPath('data.prix_vente', '18.00');

    expect((float) $ligne->fresh()->prix_vente)->toBe(18.0);
});

// ───────────────────────── suppression ─────────────────────────

it('retire une ligne d\'une carte brouillon (200)', function () {
    actingAsCarteUser(['carte_produit.delete']);
    $carte = Carte::factory()->create(); // brouillon par défaut
    $ligne = CarteProduit::factory()->create(['carte_id' => $carte->id]);

    deleteJson("/api/cartes/{$carte->id}/produits/{$ligne->id}")->assertOk();

    expect(CarteProduit::find($ligne->id))->toBeNull();
});

it('refuse de retirer une ligne d\'une carte active (409)', function () {
    actingAsCarteUser(['carte_produit.delete']);
    $carte = Carte::factory()->active()->create();
    $ligne = CarteProduit::factory()->create(['carte_id' => $carte->id]);

    deleteJson("/api/cartes/{$carte->id}/produits/{$ligne->id}")->assertStatus(409);

    expect(CarteProduit::find($ligne->id))->not->toBeNull();
});

// ───────────────────────── câblage estReferenceeDansCarteActive ─────────────────────────

it('estReferenceeDansCarteActive renvoie true quand le produit est sur une carte active', function () {
    $produit = Produit::factory()->create();
    $fiche = FicheTechnique::factory()->create(['produit_id' => $produit->id]);

    expect($fiche->estReferenceeDansCarteActive())->toBeFalse();

    $carteActive = Carte::factory()->active()->create();
    CarteProduit::factory()->create(['carte_id' => $carteActive->id, 'produit_id' => $produit->id]);

    expect($fiche->estReferenceeDansCarteActive())->toBeTrue();
});

it('ne compte pas une ligne inactive ni une carte non active', function () {
    $produit = Produit::factory()->create();
    $fiche = FicheTechnique::factory()->create(['produit_id' => $produit->id]);

    // Carte active mais ligne inactive.
    $carteActive = Carte::factory()->active()->create();
    CarteProduit::factory()->inactif()->create(['carte_id' => $carteActive->id, 'produit_id' => $produit->id]);

    // Carte brouillon avec ligne active.
    $carteBrouillon = Carte::factory()->create();
    CarteProduit::factory()->create(['carte_id' => $carteBrouillon->id, 'produit_id' => $produit->id]);

    expect($fiche->estReferenceeDansCarteActive())->toBeFalse();
});

it('bloque la suppression d\'une fiche technique dont le produit est sur une carte active (409)', function () {
    actingAsCarteUser(['fichetechnique.delete']);
    $produit = Produit::factory()->create();
    $fiche = FicheTechnique::factory()->create(['produit_id' => $produit->id]);
    $carteActive = Carte::factory()->active()->create();
    CarteProduit::factory()->create(['carte_id' => $carteActive->id, 'produit_id' => $produit->id]);

    deleteJson("/api/fiches-techniques/{$fiche->id}")->assertStatus(409);

    expect(FicheTechnique::find($fiche->id))->not->toBeNull();
});

// ───────────────────────── permissions ─────────────────────────

it('refuse l\'ajout sans la permission', function () {
    actingAsCarteUser([]);
    $carte = Carte::factory()->create();
    $produit = Produit::factory()->create();

    postJson("/api/cartes/{$carte->id}/produits", [
        'produit_id' => $produit->id,
        'prix_vente' => 9,
    ])->assertForbidden();
});
