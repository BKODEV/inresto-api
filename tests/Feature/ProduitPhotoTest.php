<?php

declare(strict_types=1);

use App\Models\Produit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;

use function Pest\Laravel\deleteJson;
use function Pest\Laravel\postJson;
use function Pest\Laravel\putJson;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('public');
});

/**
 * La photo d'un produit est le seul visuel que voit le client sur le menu
 * public. Elle ne s'écrit que par téléversement : le champ `photo` porte un
 * chemin sur le disque `public`, jamais une valeur saisie librement.
 *
 * @param  string[]  $permissions
 */
function actingAsPhotoProduitUser(array $permissions): User
{
    $user = User::factory()->create();

    foreach ($permissions as $name) {
        Permission::findOrCreate($name, 'api');
    }
    $user->givePermissionTo($permissions);
    Sanctum::actingAs($user);

    return $user;
}

it('refuse le téléversement sans la permission produit.update', function () {
    actingAsPhotoProduitUser(['produit.view']);
    $produit = Produit::factory()->create();

    postJson("/api/produits/{$produit->id}/photo", [
        'photo' => UploadedFile::fake()->image('plat.jpg'),
    ])->assertForbidden();
});

it('téléverse la photo, la stocke et renseigne le produit', function () {
    actingAsPhotoProduitUser(['produit.update']);
    $produit = Produit::factory()->create(['photo' => null]);

    $data = postJson("/api/produits/{$produit->id}/photo", [
        'photo' => UploadedFile::fake()->image('plat.jpg'),
    ])->assertOk()->json('data');

    $chemin = $produit->fresh()->photo;

    expect($chemin)->toBe("produits/{$produit->id}/photo.jpg")
        ->and($data['photo_url'])->toContain($chemin);

    Storage::disk('public')->assertExists($chemin);
});

it('efface le fichier précédent quand la photo est remplacée', function () {
    // L'extension suit le format envoyé : écraser sans supprimer laisserait un
    // `photo.jpg` orphelin à côté du nouveau `photo.webp`.
    actingAsPhotoProduitUser(['produit.update']);
    $produit = Produit::factory()->create(['photo' => null]);

    postJson("/api/produits/{$produit->id}/photo", [
        'photo' => UploadedFile::fake()->image('plat.jpg'),
    ])->assertOk();

    postJson("/api/produits/{$produit->id}/photo", [
        'photo' => UploadedFile::fake()->image('plat.webp'),
    ])->assertOk();

    Storage::disk('public')->assertMissing("produits/{$produit->id}/photo.jpg");
    Storage::disk('public')->assertExists("produits/{$produit->id}/photo.webp");
    expect($produit->fresh()->photo)->toBe("produits/{$produit->id}/photo.webp");
});

it('supprime la photo : fichier effacé et champ remis à null', function () {
    actingAsPhotoProduitUser(['produit.update']);
    $produit = Produit::factory()->create(['photo' => null]);

    postJson("/api/produits/{$produit->id}/photo", [
        'photo' => UploadedFile::fake()->image('plat.png'),
    ])->assertOk();

    deleteJson("/api/produits/{$produit->id}/photo")->assertOk();

    Storage::disk('public')->assertMissing("produits/{$produit->id}/photo.png");
    expect($produit->fresh()->photo)->toBeNull();
});

it('rejette un fichier qui n\'est pas une image (422)', function () {
    actingAsPhotoProduitUser(['produit.update']);
    $produit = Produit::factory()->create();

    postJson("/api/produits/{$produit->id}/photo", [
        'photo' => UploadedFile::fake()->create('recette.pdf', 100, 'application/pdf'),
    ])->assertUnprocessable()->assertJsonValidationErrors('photo');
});

it('ignore un chemin de photo soumis en texte sur la mise à jour du produit', function () {
    // Seul le téléversement écrit ce champ : accepter une chaîne laisserait
    // pointer le menu public vers n'importe quelle ressource.
    actingAsPhotoProduitUser(['produit.update']);
    $produit = Produit::factory()->create(['photo' => null]);

    putJson("/api/produits/{$produit->id}", [
        'libelle' => 'Attiéké poisson',
        'photo' => 'https://exemple.test/pirate.jpg',
    ])->assertOk();

    expect($produit->fresh()->photo)->toBeNull();
});

it('n\'essaie pas d\'effacer un fichier local pour une photo en URL absolue', function () {
    // Valeurs historiques : le champ pouvait porter une URL. La suppression doit
    // vider le champ sans tenter d'effacer un chemin qui n'existe pas.
    actingAsPhotoProduitUser(['produit.update']);
    $produit = Produit::factory()->create(['photo' => 'https://exemple.test/plat.jpg']);

    deleteJson("/api/produits/{$produit->id}/photo")->assertOk();

    expect($produit->fresh()->photo)->toBeNull();
});
