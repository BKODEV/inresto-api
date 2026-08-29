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

// ───────────────────────── la brigade du poste ─────────────────────────

/**
 * Un poste porte les deux moitiés de l'organisation du pass : ce qu'on y
 * produit (catégories) et QUI le tient (membres). Sans la seconde, composer une
 * brigade obligeait à dérouler tout le personnel du site.
 */
it('rattache des membres au poste', function () {
    $site = Site::factory()->create();
    $pdv = PointDeVente::factory()->create(['site_id' => $site->id]);
    actingAsChef($site);
    $commis = User::factory()->create(['prenom' => 'Aminata', 'nom' => 'D.']);
    $commis->sites()->attach($site->id);

    postJson('/api/parametres/postes-cuisine', [
        'point_de_vente_id' => $pdv->id,
        'libelle' => 'Chaud',
        'membres' => [$commis->id],
    ])
        ->assertCreated()
        ->assertJsonCount(1, 'data.membres')
        ->assertJsonPath('data.membres.0.nom', 'Aminata D.');
});

/**
 * Même distinction que pour les catégories, et le même piège : `?? []`
 * confondrait « je ne touche pas à la brigade » et « je vide la brigade ».
 */
it('laisse la brigade intacte quand la clé est absente, la vide quand elle est fournie vide', function () {
    $site = Site::factory()->create();
    $pdv = PointDeVente::factory()->create(['site_id' => $site->id]);
    actingAsChef($site);
    $commis = User::factory()->create();
    $commis->sites()->attach($site->id);
    $poste = PosteCuisine::factory()->create(['point_de_vente_id' => $pdv->id]);
    $poste->membres()->sync([$commis->id]);

    putJson("/api/parametres/postes-cuisine/{$poste->id}", ['libelle' => 'Froid'])
        ->assertOk()
        ->assertJsonCount(1, 'data.membres');

    putJson("/api/parametres/postes-cuisine/{$poste->id}", ['membres' => []])
        ->assertOk()
        ->assertJsonCount(0, 'data.membres');
});

/**
 * Un `sync` accepterait sans broncher quelqu'un d'un autre établissement : la
 * brigade le contiendrait, et le service lui serait imputé.
 */
it('refuse un membre qui n\'est pas du site du point de vente (422)', function () {
    $site = Site::factory()->create();
    $pdv = PointDeVente::factory()->create(['site_id' => $site->id]);
    actingAsChef($site);
    $etranger = User::factory()->create();
    $etranger->sites()->attach(Site::factory()->create()->id);

    postJson('/api/parametres/postes-cuisine', [
        'point_de_vente_id' => $pdv->id,
        'libelle' => 'Chaud',
        'membres' => [$etranger->id],
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('membres.0');
});

it('refuse deux fois la même personne sur un poste (422)', function () {
    $site = Site::factory()->create();
    $pdv = PointDeVente::factory()->create(['site_id' => $site->id]);
    actingAsChef($site);
    $commis = User::factory()->create();
    $commis->sites()->attach($site->id);

    postJson('/api/parametres/postes-cuisine', [
        'point_de_vente_id' => $pdv->id,
        'libelle' => 'Chaud',
        'membres' => [$commis->id, $commis->id],
    ])->assertStatus(422);
});

// ───────────────────────── personnel affectable ─────────────────────────

/**
 * LE défaut que ce lot corrige. Le sélecteur de brigade appelait `GET /users`,
 * qui exige `utilisateur.view` — une permission d'administration du personnel
 * que le chef de cuisine n'a pas. Il était donc en 403 sur le seul écran qu'il
 * est censé tenir, et ne voyait personne à inscrire.
 */
it('sert le personnel au chef, qui n\'a pourtant pas le droit d\'administrer les comptes', function () {
    $site = Site::factory()->create();
    $pdv = PointDeVente::factory()->create(['site_id' => $site->id]);
    $chef = actingAsChef($site, ['poste_cuisine.view']);
    $commis = User::factory()->create(['prenom' => 'Aminata', 'nom' => 'D.']);
    $commis->sites()->attach($site->id);

    expect($chef->can('utilisateur.view'))->toBeFalse();

    getJson("/api/points-de-vente/{$pdv->id}/personnel")
        ->assertOk()
        ->assertJsonCount(2, 'data.personnel');
});

it('ne renvoie que des noms, jamais la fiche du personnel', function () {
    // Cocher des noms ne demande pas d'ouvrir les dossiers : ni contact, ni
    // rôle, ni identifiant de connexion.
    $site = Site::factory()->create();
    $pdv = PointDeVente::factory()->create(['site_id' => $site->id]);
    actingAsChef($site, ['poste_cuisine.view']);

    $reponse = getJson("/api/points-de-vente/{$pdv->id}/personnel")->assertOk();

    expect(array_keys($reponse->json('data.personnel.0')))->toEqual(['id', 'nom']);
});

/**
 * « Être de la cuisine » se définit ici par le rattachement à un poste du point
 * de vente — c'est tout l'objet du lot.
 */
it('restreint aux membres des postes quand on le demande', function () {
    $site = Site::factory()->create();
    $pdv = PointDeVente::factory()->create(['site_id' => $site->id]);
    actingAsChef($site, ['poste_cuisine.view']);

    $cuisinier = User::factory()->create(['prenom' => 'Aminata', 'nom' => 'D.']);
    $cuisinier->sites()->attach($site->id);
    $serveur = User::factory()->create(['prenom' => 'Kouassi', 'nom' => 'A.']);
    $serveur->sites()->attach($site->id);

    $poste = PosteCuisine::factory()->create(['point_de_vente_id' => $pdv->id]);
    $poste->membres()->sync([$cuisinier->id]);

    getJson("/api/points-de-vente/{$pdv->id}/personnel?cuisine=1")
        ->assertOk()
        ->assertJsonCount(1, 'data.personnel')
        ->assertJsonPath('data.personnel.0.nom', 'Aminata D.');
});

it('ne compte pas la cuisine d\'un autre point de vente', function () {
    // Un site peut porter deux pass : le Lounge et le Restaurant n'ont pas la
    // même brigade, et mélanger les deux imputerait un service au mauvais monde.
    $site = Site::factory()->create();
    $bar = PointDeVente::factory()->create(['site_id' => $site->id]);
    $resto = PointDeVente::factory()->create(['site_id' => $site->id]);
    actingAsChef($site, ['poste_cuisine.view']);

    $duResto = User::factory()->create();
    $duResto->sites()->attach($site->id);
    PosteCuisine::factory()->create(['point_de_vente_id' => $resto->id])
        ->membres()->sync([$duResto->id]);

    getJson("/api/points-de-vente/{$bar->id}/personnel?cuisine=1")
        ->assertOk()
        ->assertJsonCount(0, 'data.personnel');
});

it('borne le personnel au site du point de vente', function () {
    $site = Site::factory()->create();
    $pdv = PointDeVente::factory()->create(['site_id' => $site->id]);
    actingAsChef($site, ['poste_cuisine.view']);
    $etranger = User::factory()->create();
    $etranger->sites()->attach(Site::factory()->create()->id);

    $reponse = getJson("/api/points-de-vente/{$pdv->id}/personnel")->assertOk();

    expect(collect($reponse->json('data.personnel'))->pluck('id'))
        ->not->toContain($etranger->id);
});

it('refuse le personnel d\'un point de vente hors périmètre', function () {
    $site = Site::factory()->create();
    actingAsChef($site, ['poste_cuisine.view']);
    $ailleurs = PointDeVente::factory()->create(['site_id' => Site::factory()->create()->id]);

    getJson("/api/points-de-vente/{$ailleurs->id}/personnel")->assertForbidden();
});

it('refuse le personnel sans le droit de lire les postes', function () {
    $site = Site::factory()->create();
    $pdv = PointDeVente::factory()->create(['site_id' => $site->id]);
    actingAsChef($site, ['session_cuisine.view']);

    getJson("/api/points-de-vente/{$pdv->id}/personnel")->assertForbidden();
});
