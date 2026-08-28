<?php

declare(strict_types=1);

use App\Models\CategorieProduit;
use App\Models\PointDeVente;
use App\Models\PosteCuisine;
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
function actingAsChef(Site $site, array $permissions = ['poste_cuisine.view', 'poste_cuisine.create', 'poste_cuisine.update', 'poste_cuisine.delete']): User
{
    foreach ($permissions as $name) {
        Permission::findOrCreate($name, 'api');
    }
    $user = User::factory()->create();
    $user->givePermissionTo($permissions);
    $user->sites()->attach($site->id);
    Sanctum::actingAs($user);

    return $user;
}

it('crée un poste avec ses catégories', function () {
    $site = Site::factory()->create();
    $pdv = PointDeVente::factory()->create(['site_id' => $site->id]);
    actingAsChef($site);
    $entrees = CategorieProduit::factory()->create(['libelle' => 'Entrées']);
    $salades = CategorieProduit::factory()->create(['libelle' => 'Salades']);

    postJson('/api/parametres/postes-cuisine', [
        'point_de_vente_id' => $pdv->id,
        'libelle' => 'Entrées froides',
        'ordre' => 1,
        'categories' => [$entrees->id, $salades->id],
    ])
        ->assertCreated()
        ->assertJsonPath('data.libelle', 'Entrées froides')
        ->assertJsonCount(2, 'data.categories');
});

/**
 * Deux postes homonymes sur un même pass seraient indiscernables à l'écran, et
 * le rattachement des catégories deviendrait un pari.
 */
it('refuse deux postes homonymes sur le même point de vente', function () {
    $site = Site::factory()->create();
    $pdv = PointDeVente::factory()->create(['site_id' => $site->id]);
    actingAsChef($site);
    PosteCuisine::factory()->create(['point_de_vente_id' => $pdv->id, 'libelle' => 'Chaud']);

    postJson('/api/parametres/postes-cuisine', [
        'point_de_vente_id' => $pdv->id,
        'libelle' => 'Chaud',
    ])->assertStatus(422);
});

/**
 * Le même libellé sur deux pass distincts est légitime : chaque point de vente
 * a son « Chaud ».
 */
it('accepte le même libellé sur un autre point de vente', function () {
    $site = Site::factory()->create();
    $pdv = PointDeVente::factory()->create(['site_id' => $site->id]);
    $autrePdv = PointDeVente::factory()->create(['site_id' => $site->id]);
    actingAsChef($site);
    PosteCuisine::factory()->create(['point_de_vente_id' => $pdv->id, 'libelle' => 'Chaud']);

    postJson('/api/parametres/postes-cuisine', [
        'point_de_vente_id' => $autrePdv->id,
        'libelle' => 'Chaud',
    ])->assertCreated();
});

/**
 * Le rattachement est un ENSEMBLE que l'écran repose en entier, pas une liste à
 * laquelle on ajoute.
 */
it('remplace le rattachement des catégories à la mise à jour', function () {
    $site = Site::factory()->create();
    $pdv = PointDeVente::factory()->create(['site_id' => $site->id]);
    actingAsChef($site);
    $a = CategorieProduit::factory()->create();
    $b = CategorieProduit::factory()->create();
    $poste = PosteCuisine::factory()->create(['point_de_vente_id' => $pdv->id]);
    $poste->categories()->sync([$a->id]);

    putJson("/api/parametres/postes-cuisine/{$poste->id}", ['categories' => [$b->id]])
        ->assertOk()
        ->assertJsonCount(1, 'data.categories')
        ->assertJsonPath('data.categories.0.id', $b->id);
});

/**
 * Clé absente et tableau vide sont deux intentions distinctes qu'un `?? []`
 * confondrait : renommer un poste ne doit pas le vider de ses catégories.
 */
it('laisse le rattachement intact quand la clé est absente', function () {
    $site = Site::factory()->create();
    $pdv = PointDeVente::factory()->create(['site_id' => $site->id]);
    actingAsChef($site);
    $a = CategorieProduit::factory()->create();
    $poste = PosteCuisine::factory()->create(['point_de_vente_id' => $pdv->id]);
    $poste->categories()->sync([$a->id]);

    putJson("/api/parametres/postes-cuisine/{$poste->id}", ['libelle' => 'Renommé'])
        ->assertOk()
        ->assertJsonCount(1, 'data.categories');
});

it('détache tout sur un tableau vide', function () {
    $site = Site::factory()->create();
    $pdv = PointDeVente::factory()->create(['site_id' => $site->id]);
    actingAsChef($site);
    $poste = PosteCuisine::factory()->create(['point_de_vente_id' => $pdv->id]);
    $poste->categories()->sync([CategorieProduit::factory()->create()->id]);

    putJson("/api/parametres/postes-cuisine/{$poste->id}", ['categories' => []])
        ->assertOk()
        ->assertJsonCount(0, 'data.categories');
});

it('supprime un poste et son rattachement', function () {
    $site = Site::factory()->create();
    $pdv = PointDeVente::factory()->create(['site_id' => $site->id]);
    actingAsChef($site);
    $poste = PosteCuisine::factory()->create(['point_de_vente_id' => $pdv->id]);
    $poste->categories()->sync([CategorieProduit::factory()->create()->id]);

    deleteJson("/api/parametres/postes-cuisine/{$poste->id}")->assertOk();

    expect(PosteCuisine::find($poste->id))->toBeNull()
        ->and(DB::table('categorie_poste_cuisine')->where('poste_cuisine_id', $poste->id)->count())->toBe(0);
});

it('n\'expose que les postes du périmètre de l\'utilisateur', function () {
    $site = Site::factory()->create();
    $pdv = PointDeVente::factory()->create(['site_id' => $site->id]);
    actingAsChef($site);
    PosteCuisine::factory()->create(['point_de_vente_id' => $pdv->id]);

    $autreSite = Site::factory()->create();
    $autrePdv = PointDeVente::factory()->create(['site_id' => $autreSite->id]);
    PosteCuisine::factory()->create(['point_de_vente_id' => $autrePdv->id]);

    getJson('/api/parametres/postes-cuisine')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

it('refuse la création sans permission', function () {
    $site = Site::factory()->create();
    $pdv = PointDeVente::factory()->create(['site_id' => $site->id]);
    actingAsChef($site, ['poste_cuisine.view']);

    postJson('/api/parametres/postes-cuisine', [
        'point_de_vente_id' => $pdv->id,
        'libelle' => 'Chaud',
    ])->assertForbidden();
});
