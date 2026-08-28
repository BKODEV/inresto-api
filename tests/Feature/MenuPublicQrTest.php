<?php

declare(strict_types=1);

use App\Models\Carte;
use App\Models\CarteProduit;
use App\Models\CategorieProduit;
use App\Models\ParametreEtablissement;
use App\Models\PointDeVente;
use App\Models\Produit;
use App\Models\Site;
use App\Models\TableSalle;
use App\Models\User;
use App\Services\PdfService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;

use function Pest\Laravel\artisan;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

uses(RefreshDatabase::class);

/**
 * @param  string[]  $permissions
 */
function actingAsQrUser(array $permissions = []): User
{
    $user = User::factory()->create();

    foreach ($permissions as $name) {
        Permission::findOrCreate($name, 'api');
    }

    $user->givePermissionTo($permissions);
    Sanctum::actingAs($user);

    return $user;
}

/**
 * Salle complète prête à être scannée : un site paramétré, un PDV, sa carte
 * active, un produit dessus, et une table porteuse de son jeton.
 *
 * @return array{table: TableSalle, pointDeVente: PointDeVente, carte: Carte, produit: Produit}
 */
function salleAvecCarteActive(): array
{
    $site = Site::factory()->create();
    ParametreEtablissement::factory()->create([
        'site_id' => $site->id,
        'nom_commercial' => 'Le Comptoir du Plateau',
        'devise' => 'XOF',
    ]);

    $pointDeVente = PointDeVente::factory()->create(['site_id' => $site->id]);
    $carte = Carte::factory()->active()->create(['point_de_vente_id' => $pointDeVente->id]);

    $categorie = CategorieProduit::factory()->create(['libelle' => 'Entrées', 'ordre' => 1]);
    $produit = Produit::factory()->create([
        'categorie_id' => $categorie->id,
        'libelle' => 'Attiéké poisson',
        'description' => 'Servi avec sauce claire',
        'allergenes' => 'Poisson',
    ]);

    CarteProduit::factory()->create([
        'carte_id' => $carte->id,
        'produit_id' => $produit->id,
        'prix_vente' => 4500,
    ]);

    $table = TableSalle::factory()->create(['point_de_vente_id' => $pointDeVente->id]);

    return compact('table', 'pointDeVente', 'carte', 'produit');
}

// ───────────────────── Jeton public de la table ─────────────────────

it('génère un jeton public à la création d\'une table', function () {
    actingAsQrUser(['table.create']);
    $pdv = PointDeVente::factory()->create();

    $reponse = postJson('/api/tables', [
        'point_de_vente_id' => $pdv->id,
        'numero' => 'T1',
        'capacite' => 4,
    ])->assertCreated();

    $token = $reponse->json('data.public_token');

    expect($token)->toBeString()->not->toBeEmpty()
        ->and(strlen($token))->toBeGreaterThanOrEqual(32);
});

it('n\'attribue jamais le même jeton à deux tables', function () {
    $tokens = TableSalle::factory()->count(15)->create()->pluck('public_token');

    expect($tokens->unique())->toHaveCount(15)
        ->and($tokens->filter())->toHaveCount(15);
});

it('expose le lien public du menu construit depuis la config', function () {
    config(['inresto.public_menu_base_url' => 'https://comptoir.exemple.ci']);

    actingAsQrUser(['table.view']);
    $table = TableSalle::factory()->create();

    getJson("/api/tables/{$table->id}")
        ->assertOk()
        ->assertJsonPath('data.menu_url', "https://comptoir.exemple.ci/menu/{$table->public_token}");
});

// ───────────────────── Backfill ─────────────────────

it('attribue un jeton aux tables qui n\'en ont pas', function () {
    $sansToken = TableSalle::factory()->count(3)->sansToken()->create();

    artisan('inresto:backfill-table-tokens')->assertSuccessful();

    $sansToken->each(fn (TableSalle $table) => expect($table->fresh()->public_token)->not->toBeNull());
});

it('ne touche pas aux jetons existants quand on relance le backfill', function () {
    $equipee = TableSalle::factory()->create();
    $tokenInitial = $equipee->public_token;

    TableSalle::factory()->sansToken()->create();

    artisan('inresto:backfill-table-tokens')->assertSuccessful();
    artisan('inresto:backfill-table-tokens')->assertSuccessful();

    // Un backfill qui réattribuerait un jeton invaliderait des QR déjà imprimés.
    expect($equipee->fresh()->public_token)->toBe($tokenInitial)
        ->and(TableSalle::whereNull('public_token')->count())->toBe(0);
});

// ───────────────────── Régénération ─────────────────────

it('régénère le jeton et invalide l\'ancien lien', function () {
    ['table' => $table] = salleAvecCarteActive();
    $ancienToken = $table->public_token;

    actingAsQrUser(['table.update']);

    $nouveauToken = postJson("/api/tables/{$table->id}/regenerer-token")
        ->assertOk()
        ->json('data.public_token');

    expect($nouveauToken)->not->toBe($ancienToken);

    getJson("/api/public/menu/{$ancienToken}")->assertNotFound();
    getJson("/api/public/menu/{$nouveauToken}")->assertOk();
});

it('refuse la régénération du jeton sans la permission table.update', function () {
    $table = TableSalle::factory()->create();
    actingAsQrUser(['table.view']);

    postJson("/api/tables/{$table->id}/regenerer-token")->assertForbidden();

    expect($table->fresh()->public_token)->toBe($table->public_token);
});

// ───────────────────── Menu public ─────────────────────

it('sert le menu de la carte active sans authentification', function () {
    ['table' => $table] = salleAvecCarteActive();

    getJson("/api/public/menu/{$table->public_token}")
        ->assertOk()
        ->assertJsonPath('data.menu_indisponible', false)
        ->assertJsonPath('data.etablissement.nom', 'Le Comptoir du Plateau')
        ->assertJsonPath('data.etablissement.devise', 'XOF')
        ->assertJsonPath('data.categories.0.libelle', 'Entrées')
        ->assertJsonPath('data.categories.0.produits.0.libelle', 'Attiéké poisson')
        ->assertJsonPath('data.categories.0.produits.0.description', 'Servi avec sauce claire')
        ->assertJsonPath('data.categories.0.produits.0.allergenes', 'Poisson')
        // Nombre JSON (et non chaîne « 4500.00 ») : le front formate lui-même
        // avec la devise de l'établissement.
        ->assertJsonPath('data.categories.0.produits.0.prix', 4500);
});

it('groupe les produits du menu par catégorie dans l\'ordre d\'affichage', function () {
    ['table' => $table, 'carte' => $carte] = salleAvecCarteActive();

    $plats = CategorieProduit::factory()->create(['libelle' => 'Plats', 'ordre' => 2]);
    CarteProduit::factory()->create([
        'carte_id' => $carte->id,
        'produit_id' => Produit::factory()->create(['categorie_id' => $plats->id])->id,
    ]);

    getJson("/api/public/menu/{$table->public_token}")
        ->assertOk()
        ->assertJsonCount(2, 'data.categories')
        ->assertJsonPath('data.categories.0.libelle', 'Entrées')
        ->assertJsonPath('data.categories.1.libelle', 'Plats');
});

it('n\'expose aucune donnée interne ou de gestion sur le menu public', function () {
    ['table' => $table, 'pointDeVente' => $pdv, 'produit' => $produit] = salleAvecCarteActive();

    $corps = getJson("/api/public/menu/{$table->public_token}")->assertOk()->json('data');
    $brut = json_encode($corps);

    // Aucune clé technique : le client ne doit rien pouvoir déduire du back-office.
    foreach (['id', 'carte_id', 'produit_id', 'point_de_vente_id', 'site_id', 'statut',
        'cout', 'cout_matiere', 'marge', 'stock', 'fiche_technique', 'public_token'] as $cle) {
        expect($brut)->not->toContain("\"{$cle}\":");
    }

    expect($corps['point_de_vente'])->toBe(['nom' => $pdv->nom, 'type' => $pdv->type->value])
        ->and(array_keys($corps['categories'][0]['produits'][0]))
        ->toBe(['libelle', 'description', 'allergenes', 'photo_url', 'prix'])
        ->and($brut)->not->toContain($table->public_token);
});

it('masque du menu public un produit désactivé sur la carte', function () {
    ['table' => $table, 'carte' => $carte] = salleAvecCarteActive();

    $retire = Produit::factory()->create(['libelle' => 'Bissap maison']);
    CarteProduit::factory()->inactif()->create([
        'carte_id' => $carte->id,
        'produit_id' => $retire->id,
    ]);

    $corps = getJson("/api/public/menu/{$table->public_token}")->assertOk()->json();

    expect(json_encode($corps))->not->toContain('Bissap maison');
});

it('masque du menu public un produit désactivé globalement', function () {
    ['table' => $table, 'carte' => $carte] = salleAvecCarteActive();

    $inactif = Produit::factory()->inactif()->create(['libelle' => 'Gnamankoudji']);
    CarteProduit::factory()->create([
        'carte_id' => $carte->id,
        'produit_id' => $inactif->id,
    ]);

    $corps = getJson("/api/public/menu/{$table->public_token}")->assertOk()->json();

    expect(json_encode($corps))->not->toContain('Gnamankoudji');
});

it('signale un menu indisponible plutôt qu\'une erreur quand le PDV n\'a pas de carte active', function () {
    $pdv = PointDeVente::factory()->create();
    Carte::factory()->create(['point_de_vente_id' => $pdv->id]); // brouillon
    $table = TableSalle::factory()->create(['point_de_vente_id' => $pdv->id]);

    getJson("/api/public/menu/{$table->public_token}")
        ->assertOk()
        ->assertJsonPath('data.menu_indisponible', true)
        ->assertJsonPath('data.categories', []);
});

it('renvoie 404 sur un jeton inconnu', function () {
    getJson('/api/public/menu/'.str_repeat('z', 32))->assertNotFound();
});

/**
 * Un autocollant QR survit à la table qu'il désigne. Archiver doit donc éteindre
 * le menu, et le faire sans révéler que la table a existé : même 404 neutre que
 * pour un jeton inventé.
 */
it('renvoie 404 sur le jeton d\'une table archivée', function () {
    ['table' => $table] = salleAvecCarteActive();
    $url = "/api/public/menu/{$table->public_token}";

    getJson($url)->assertOk();

    $table->update(['archivee_le' => now()]);

    getJson($url)->assertNotFound();
});

it('limite le nombre d\'appels au menu public', function () {
    ['table' => $table] = salleAvecCarteActive();
    $url = "/api/public/menu/{$table->public_token}";

    // Le throttle protège du balayage de jetons sur un endpoint sans auth.
    foreach (range(1, 60) as $_appel) {
        getJson($url)->assertOk();
    }

    getJson($url)->assertStatus(429);
});

// ───────────────────── PDF QR ─────────────────────

it('génère le PDF du QR d\'une table', function () {
    ['table' => $table] = salleAvecCarteActive();
    actingAsQrUser(['table.view']);

    $reponse = getJson("/api/tables/{$table->id}/qr-pdf")->assertOk();

    expect($reponse->headers->get('content-type'))->toContain('application/pdf');
});

it('génère la planche de QR d\'un point de vente', function () {
    ['pointDeVente' => $pdv] = salleAvecCarteActive();
    TableSalle::factory()->count(4)->create(['point_de_vente_id' => $pdv->id]);

    actingAsQrUser(['table.view']);

    $reponse = getJson("/api/points-de-vente/{$pdv->id}/qr-planche-pdf")->assertOk();

    expect($reponse->headers->get('content-type'))->toContain('application/pdf');
});

/**
 * La planche sert à imprimer les autocollants à coller en salle. Y faire figurer
 * une table archivée remettrait en circulation un QR qui ne sert plus de menu.
 * Le HTML est inspecté directement : le PDF binaire ne se lit pas en assertion.
 */
it('omet les tables archivées de la planche QR', function () {
    ['pointDeVente' => $pdv] = salleAvecCarteActive();
    TableSalle::factory()->create(['point_de_vente_id' => $pdv->id, 'numero' => 'EN-SALLE']);
    TableSalle::factory()->archivee()->create(['point_de_vente_id' => $pdv->id, 'numero' => 'RETIREE']);

    $html = app(PdfService::class)->htmlQrPlanchePointDeVente($pdv);

    expect($html)->toContain('EN-SALLE')
        ->and($html)->not->toContain('RETIREE');
});

it('refuse les PDF de QR sans la permission table.view', function () {
    ['table' => $table, 'pointDeVente' => $pdv] = salleAvecCarteActive();
    actingAsQrUser([]);

    getJson("/api/tables/{$table->id}/qr-pdf")->assertForbidden();
    getJson("/api/points-de-vente/{$pdv->id}/qr-planche-pdf")->assertForbidden();
});
