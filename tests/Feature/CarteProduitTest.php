<?php

declare(strict_types=1);

use App\Models\Carte;
use App\Models\CarteProduit;
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

it('ajoute un produit avec un ordre et le restitue', function () {
    actingAsCarteUser(['carte_produit.create']);
    $carte = Carte::factory()->create();
    $produit = Produit::factory()->create();

    postJson("/api/cartes/{$carte->id}/produits", [
        'produit_id' => $produit->id,
        'prix_vente' => 12.50,
        'ordre' => 3,
    ])
        ->assertCreated()
        ->assertJsonPath('data.ordre', 3);
});

it('expose la catégorie du produit ajouté (corrige « sans catégorie »)', function () {
    actingAsCarteUser(['carte_produit.create']);
    $carte = Carte::factory()->create();
    $categorie = CategorieProduit::factory()->create(['libelle' => 'Entrées']);
    $produit = Produit::factory()->create(['categorie_id' => $categorie->id]);

    postJson("/api/cartes/{$carte->id}/produits", [
        'produit_id' => $produit->id,
        'prix_vente' => 10,
    ])
        ->assertCreated()
        ->assertJsonPath('data.produit.categorie.id', $categorie->id)
        ->assertJsonPath('data.produit.categorie.libelle', 'Entrées');
});

// ───────────────────────── ordre : placement & décalage ─────────────────────────

it('place les nouveaux produits en fin de section (1 puis 2) sans ordre fourni', function () {
    actingAsCarteUser(['carte_produit.create']);
    $carte = Carte::factory()->create();
    $categorie = CategorieProduit::factory()->create();
    $premier = Produit::factory()->create(['categorie_id' => $categorie->id]);
    $second = Produit::factory()->create(['categorie_id' => $categorie->id]);

    postJson("/api/cartes/{$carte->id}/produits", ['produit_id' => $premier->id, 'prix_vente' => 10])
        ->assertCreated()
        ->assertJsonPath('data.ordre', 1);

    postJson("/api/cartes/{$carte->id}/produits", ['produit_id' => $second->id, 'prix_vente' => 10])
        ->assertCreated()
        ->assertJsonPath('data.ordre', 2);
});

it('décale les voisins quand on insère un produit à un ordre déjà occupé', function () {
    actingAsCarteUser(['carte_produit.create']);
    $carte = Carte::factory()->create();
    $categorie = CategorieProduit::factory()->create();
    $existant = Produit::factory()->create(['categorie_id' => $categorie->id]);
    $nouveau = Produit::factory()->create(['categorie_id' => $categorie->id]);
    $ligneExistante = CarteProduit::factory()->create([
        'carte_id' => $carte->id,
        'produit_id' => $existant->id,
        'ordre' => 1,
    ]);

    postJson("/api/cartes/{$carte->id}/produits", [
        'produit_id' => $nouveau->id,
        'prix_vente' => 10,
        'ordre' => 1,
    ])
        ->assertCreated()
        ->assertJsonPath('data.ordre', 1);

    expect($ligneExistante->fresh()->ordre)->toBe(2);
});

it('numérote l\'ordre indépendamment d\'une section à l\'autre', function () {
    actingAsCarteUser(['carte_produit.create']);
    $carte = Carte::factory()->create();
    $entrees = CategorieProduit::factory()->create();
    $plats = CategorieProduit::factory()->create();
    $entree = Produit::factory()->create(['categorie_id' => $entrees->id]);
    $plat = Produit::factory()->create(['categorie_id' => $plats->id]);

    postJson("/api/cartes/{$carte->id}/produits", ['produit_id' => $entree->id, 'prix_vente' => 10])
        ->assertJsonPath('data.ordre', 1);

    // La section « Plats » repart à 1, sans tenir compte de la section « Entrées ».
    postJson("/api/cartes/{$carte->id}/produits", ['produit_id' => $plat->id, 'prix_vente' => 10])
        ->assertJsonPath('data.ordre', 1);
});

it('réinsère une ligne et décale ses voisins quand on change son ordre', function () {
    actingAsCarteUser(['carte_produit.update']);
    $carte = Carte::factory()->create();
    $categorie = CategorieProduit::factory()->create();
    $p1 = Produit::factory()->create(['categorie_id' => $categorie->id]);
    $p2 = Produit::factory()->create(['categorie_id' => $categorie->id]);
    $l1 = CarteProduit::factory()->create(['carte_id' => $carte->id, 'produit_id' => $p1->id, 'ordre' => 1]);
    $l2 = CarteProduit::factory()->create(['carte_id' => $carte->id, 'produit_id' => $p2->id, 'ordre' => 2]);

    patchJson("/api/cartes/{$carte->id}/produits/{$l2->id}", ['ordre' => 1])
        ->assertOk()
        ->assertJsonPath('data.ordre', 1);

    expect($l1->fresh()->ordre)->toBe(2);
    expect($l2->fresh()->ordre)->toBe(1);
});

it('liste les lignes triées par ordre croissant', function () {
    actingAsCarteUser(['carte_produit.view']);
    $carte = Carte::factory()->create();
    $deuxieme = CarteProduit::factory()->create(['carte_id' => $carte->id, 'ordre' => 2]);
    $premier = CarteProduit::factory()->create(['carte_id' => $carte->id, 'ordre' => 1]);

    getJson("/api/cartes/{$carte->id}/produits")
        ->assertOk()
        ->assertJsonPath('data.produits.0.id', $premier->id)
        ->assertJsonPath('data.produits.1.id', $deuxieme->id);
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
