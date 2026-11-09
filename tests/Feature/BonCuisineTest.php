<?php

declare(strict_types=1);

use App\Enums\Statut;
use App\Models\BonCuisine;
use App\Models\Caisse;
use App\Models\Carte;
use App\Models\CarteProduit;
use App\Models\CategorieProduit;
use App\Models\CommandeClient;
use App\Models\LigneCommandeClient;
use App\Models\PointDeVente;
use App\Models\PosteCuisine;
use App\Models\Produit;
use App\Models\SessionCaisse;
use App\Models\Site;
use App\Models\TableSalle;
use App\Models\User;
use App\Services\PdfService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;

use function Pest\Laravel\deleteJson;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

uses(RefreshDatabase::class);

/**
 * Une salle prête à envoyer : site → PDV → caisse → session, table, carte
 * active, et un serveur connecté qui peut aussi annuler.
 */
function bonSalle(): object
{
    $site = Site::factory()->create();
    $pdv = PointDeVente::factory()->create(['site_id' => $site->id]);
    $caisse = Caisse::factory()->create(['point_de_vente_id' => $pdv->id]);
    $session = SessionCaisse::factory()->create(['caisse_id' => $caisse->id]);
    $table = TableSalle::factory()->create(['point_de_vente_id' => $pdv->id]);
    $carte = Carte::factory()->active()->create(['point_de_vente_id' => $pdv->id]);

    $permissions = ['commande_client.view', 'commande_client.update', 'annulation.create'];
    foreach ($permissions as $name) {
        Permission::findOrCreate($name, 'api');
    }
    $serveur = User::factory()->create(['prenom' => 'Kouassi', 'nom' => 'A.']);
    $serveur->givePermissionTo($permissions);
    $serveur->sites()->attach($site->id);
    Sanctum::actingAs($serveur);

    $commande = CommandeClient::factory()->create([
        'table_id' => $table->id,
        'table_numero' => $table->numero,
        'session_caisse_id' => $session->id,
        'user_id' => $serveur->id,
    ]);

    return (object) compact('site', 'pdv', 'table', 'carte', 'serveur', 'commande');
}

function bonProduit(object $ctx, string $libelle, ?CategorieProduit $categorie = null): Produit
{
    $produit = Produit::factory()->create(['libelle' => $libelle, 'categorie_id' => $categorie?->id]);
    CarteProduit::factory()->create(['carte_id' => $ctx->carte->id, 'produit_id' => $produit->id, 'prix_vente' => 5000]);

    return $produit;
}

function bonPoste(object $ctx, string $libelle, array $categories = [], array $attributs = []): PosteCuisine
{
    $poste = PosteCuisine::factory()->create(['point_de_vente_id' => $ctx->pdv->id, 'libelle' => $libelle, ...$attributs]);
    $poste->categories()->sync(collect($categories)->pluck('id')->all());

    return $poste;
}

/**
 * @param  array<int, array{0: Produit, 1: int, 2?: string}>  $lignes
 */
function bonEnvoyer(object $ctx, array $lignes): void
{
    postJson("/api/commandes-client/{$ctx->commande->id}/envoyer-en-cuisine", [
        'lignes' => collect($lignes)->map(fn (array $l): array => [
            'produit_id' => $l[0]->id,
            'quantite' => $l[1],
            'commentaire' => $l[2] ?? null,
        ])->all(),
    ])->assertOk();
}

// ───────────────────────── découpage par poste ─────────────────────────

it('découpe un envoi en un bon par poste, numérotés à la suite', function () {
    $ctx = bonSalle();
    $entrees = CategorieProduit::factory()->create();
    $plats = CategorieProduit::factory()->create();
    bonPoste($ctx, 'Froid', [$entrees], ['ordre' => 1, 'par_defaut' => true]);
    bonPoste($ctx, 'Chaud', [$plats], ['ordre' => 2]);

    bonEnvoyer($ctx, [
        [bonProduit($ctx, 'Salade', $entrees), 2],
        [bonProduit($ctx, 'Poulet braisé', $plats), 1, 'bien cuit'],
        [bonProduit($ctx, 'Attiéké poisson', $plats), 3],
    ]);

    $bons = BonCuisine::query()->with('lignes')->orderBy('numero')->get();

    expect($bons)->toHaveCount(2)
        ->and($bons->pluck('poste_libelle')->all())->toBe(['Froid', 'Chaud'])
        ->and($bons->pluck('numero')->all())->toBe([1, 2])
        ->and($bons->pluck('partie')->all())->toBe([1, 2])
        ->and($bons->pluck('parties')->unique()->all())->toBe([2])
        ->and($bons->pluck('type')->map->value->unique()->all())->toBe(['envoi'])
        ->and($bons[0]->lignes->pluck('produit_libelle')->all())->toBe(['Salade'])
        ->and($bons[1]->lignes->pluck('produit_libelle')->all())->toBe(['Poulet braisé', 'Attiéké poisson'])
        ->and($bons[1]->lignes[0]->commentaire)->toBe('bien cuit')
        ->and($bons[1]->user_id)->toBe($ctx->serveur->id);
});

/**
 * À l'écran, une catégorie libre est montrée à tous les postes. Sur papier, ce
 * serait un plat imprimé — et cuit — autant de fois qu'il y a de postes.
 */
it('envoie au seul poste par défaut ce que personne ne produit', function () {
    $ctx = bonSalle();
    $plats = CategorieProduit::factory()->create();
    bonPoste($ctx, 'Cuisine principale', [], ['par_defaut' => true]);
    bonPoste($ctx, 'Chaud', [$plats]);

    bonEnvoyer($ctx, [
        [bonProduit($ctx, 'Jus de bissap', CategorieProduit::factory()->create()), 1],
        [bonProduit($ctx, 'Pain'), 1],
    ]);

    $bons = BonCuisine::query()->with('lignes')->get();

    expect($bons)->toHaveCount(1)
        ->and($bons[0]->poste_libelle)->toBe('Cuisine principale')
        ->and($bons[0]->lignes)->toHaveCount(2);
});

it('renvoie au poste par défaut les catégories d\'un poste inactif', function () {
    $ctx = bonSalle();
    $desserts = CategorieProduit::factory()->create();
    bonPoste($ctx, 'Cuisine', [], ['par_defaut' => true]);
    bonPoste($ctx, 'Pâtisserie', [$desserts], ['statut' => Statut::Inactif->value]);

    bonEnvoyer($ctx, [[bonProduit($ctx, 'Fondant', $desserts), 1]]);

    expect(BonCuisine::query()->sole()->poste_libelle)->toBe('Cuisine');
});

it('émet un bon unique « Cuisine » quand aucun poste n\'est déclaré', function () {
    $ctx = bonSalle();

    bonEnvoyer($ctx, [
        [bonProduit($ctx, 'Salade', CategorieProduit::factory()->create()), 1],
        [bonProduit($ctx, 'Poulet', CategorieProduit::factory()->create()), 1],
    ]);

    $bon = BonCuisine::query()->with('lignes')->sole();

    expect($bon->poste_cuisine_id)->toBeNull()
        ->and($bon->poste_libelle)->toBe('Cuisine')
        ->and($bon->lignes)->toHaveCount(2);
});

// ───────────────────────── tours suivants ─────────────────────────

/**
 * Le dessert commandé après les plats : seul le nouveau plat part, et le bon
 * dit « ajout » — la table mange déjà.
 */
it('n\'envoie que les nouvelles lignes au tour suivant, en bon d\'ajout', function () {
    $ctx = bonSalle();
    bonEnvoyer($ctx, [[bonProduit($ctx, 'Poulet'), 2]]);
    bonEnvoyer($ctx, [[bonProduit($ctx, 'Fondant'), 1]]);

    $bons = BonCuisine::query()->with('lignes')->orderBy('numero')->get();

    expect($bons)->toHaveCount(2)
        ->and($bons[0]->type->value)->toBe('envoi')
        ->and($bons[1]->type->value)->toBe('ajout')
        ->and($bons[1]->lignes->pluck('produit_libelle')->all())->toBe(['Fondant']);
});

// ───────────────────────── numérotation ─────────────────────────

it('repart à 1 chaque jour', function () {
    $ctx = bonSalle();
    bonEnvoyer($ctx, [[bonProduit($ctx, 'Poulet'), 1]]);
    bonEnvoyer($ctx, [[bonProduit($ctx, 'Frites'), 1]]);

    $this->travel(1)->days();
    bonEnvoyer($ctx, [[bonProduit($ctx, 'Fondant'), 1]]);

    expect(BonCuisine::query()->orderBy('id')->pluck('numero')->all())->toBe([1, 2, 1]);
});

it('numérote chaque point de vente séparément', function () {
    $ctx = bonSalle();
    BonCuisine::factory()->create(['point_de_vente_id' => PointDeVente::factory()->create(['site_id' => $ctx->site->id])->id, 'numero' => 7]);

    bonEnvoyer($ctx, [[bonProduit($ctx, 'Poulet'), 1]]);

    expect(BonCuisine::query()->where('point_de_vente_id', $ctx->pdv->id)->sole()->numero)->toBe(1);
});

/**
 * Un papier parti en cuisine ne change plus : renommer le plat ou déplacer sa
 * catégorie ne doit pas réécrire le bon, sinon la réimpression mentirait.
 */
it('fige le bon à son émission', function () {
    $ctx = bonSalle();
    $plats = CategorieProduit::factory()->create();
    $chaud = bonPoste($ctx, 'Chaud', [$plats], ['par_defaut' => true]);
    $produit = bonProduit($ctx, 'Poulet', $plats);
    bonEnvoyer($ctx, [[$produit, 1]]);

    $produit->update(['libelle' => 'Poulet DG']);
    $chaud->update(['libelle' => 'Grill']);

    getJson("/api/commandes-client/{$ctx->commande->id}/bons-cuisine")
        ->assertOk()
        ->assertJsonPath('data.0.poste_libelle', 'Chaud')
        ->assertJsonPath('data.0.lignes.0.produit_libelle', 'Poulet');
});

// ───────────────────────── annulations ─────────────────────────

it('émet un bon d\'annulation au poste qui a reçu le plat', function () {
    $ctx = bonSalle();
    $plats = CategorieProduit::factory()->create();
    bonPoste($ctx, 'Cuisine', [], ['par_defaut' => true]);
    $chaud = bonPoste($ctx, 'Chaud', [$plats]);
    bonEnvoyer($ctx, [[bonProduit($ctx, 'Poulet', $plats), 2]]);

    // La catégorie change de poste après l'envoi : c'est le Chaud qui a le
    // plat sur le feu, c'est lui qu'il faut prévenir.
    $chaud->categories()->sync([]);

    $ligne = LigneCommandeClient::query()->sole();
    deleteJson("/api/commandes-client/{$ctx->commande->id}/lignes/{$ligne->id}", ['motif' => 'client parti'])
        ->assertOk();

    $annulation = BonCuisine::query()->with('lignes')->where('type', 'annulation')->sole();

    expect($annulation->poste_libelle)->toBe('Chaud')
        ->and($annulation->numero)->toBe(2)
        ->and($annulation->lignes[0]->quantite)->toBe(2);
});

it('n\'émet aucun bon pour une ligne de brouillon retirée', function () {
    $ctx = bonSalle();
    postJson("/api/commandes-client/{$ctx->commande->id}/lignes", [
        'produit_id' => bonProduit($ctx, 'Poulet')->id,
        'quantite' => 1,
    ])->assertCreated();
    $ligne = LigneCommandeClient::query()->sole();

    deleteJson("/api/commandes-client/{$ctx->commande->id}/lignes/{$ligne->id}")->assertOk();

    expect(BonCuisine::query()->count())->toBe(0);
});

it('n\'émet aucun bon d\'annulation pour une ligne déjà servie', function () {
    $ctx = bonSalle();
    bonEnvoyer($ctx, [[bonProduit($ctx, 'Poulet'), 1]]);
    $ligne = LigneCommandeClient::query()->sole();
    $ligne->forceFill(['prete_le' => now(), 'servie_le' => now()])->save();

    deleteJson("/api/commandes-client/{$ctx->commande->id}/lignes/{$ligne->id}", ['motif' => 'renvoyé', 'sort' => 'perte'])
        ->assertOk();

    expect(BonCuisine::query()->where('type', 'annulation')->count())->toBe(0);
});

it('annule chez chaque poste concerné quand la note entière est annulée', function () {
    $ctx = bonSalle();
    $entrees = CategorieProduit::factory()->create();
    $plats = CategorieProduit::factory()->create();
    bonPoste($ctx, 'Froid', [$entrees], ['ordre' => 1, 'par_defaut' => true]);
    bonPoste($ctx, 'Chaud', [$plats], ['ordre' => 2]);
    bonEnvoyer($ctx, [
        [bonProduit($ctx, 'Salade', $entrees), 1],
        [bonProduit($ctx, 'Poulet', $plats), 1],
    ]);

    postJson("/api/commandes-client/{$ctx->commande->id}/annuler", ['motif' => 'client parti'])->assertOk();

    $annulations = BonCuisine::query()->where('type', 'annulation')->orderBy('numero')->get();

    expect($annulations->pluck('poste_libelle')->all())->toBe(['Froid', 'Chaud'])
        ->and($annulations->pluck('numero')->all())->toBe([3, 4]);
});

// ───────────────────────── lecture ─────────────────────────

it('sert les bons d\'une note sans aucun prix', function () {
    $ctx = bonSalle();
    bonEnvoyer($ctx, [[bonProduit($ctx, 'Poulet'), 2, 'sans piment']]);

    $reponse = getJson("/api/commandes-client/{$ctx->commande->id}/bons-cuisine")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.numero', 1)
        ->assertJsonPath('data.0.type', 'envoi')
        ->assertJsonPath('data.0.table_numero', $ctx->table->numero)
        ->assertJsonPath('data.0.serveur', 'Kouassi A.')
        ->assertJsonPath('data.0.lignes.0.quantite', 2)
        ->assertJsonPath('data.0.lignes.0.commentaire', 'sans piment');

    expect(array_keys($reponse->json('data.0.lignes.0')))->not->toContain('prix_unitaire');
});

it('sert un bon par son identifiant', function () {
    $ctx = bonSalle();
    bonEnvoyer($ctx, [[bonProduit($ctx, 'Poulet'), 1]]);
    $bon = BonCuisine::query()->sole();

    getJson("/api/bons-cuisine/{$bon->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $bon->id)
        ->assertJsonPath('data.poste_libelle', 'Cuisine');
});

it('refuse les bons sans le droit de lire les commandes', function () {
    $ctx = bonSalle();
    bonEnvoyer($ctx, [[bonProduit($ctx, 'Poulet'), 1]]);

    $sansDroit = User::factory()->create();
    $sansDroit->sites()->attach($ctx->site->id);
    Sanctum::actingAs($sansDroit);

    getJson("/api/commandes-client/{$ctx->commande->id}/bons-cuisine")->assertForbidden();
});

it('refuse les bons d\'un site hors périmètre', function () {
    $ctx = bonSalle();
    bonEnvoyer($ctx, [[bonProduit($ctx, 'Poulet'), 1]]);
    $bon = BonCuisine::query()->sole();

    $ailleurs = User::factory()->create();
    $ailleurs->givePermissionTo('commande_client.view');
    $ailleurs->sites()->attach(Site::factory()->create()->id);
    Sanctum::actingAs($ailleurs);

    getJson("/api/bons-cuisine/{$bon->id}")->assertForbidden();
});

// ───────────────────────── impression ─────────────────────────

/**
 * La file de la caisse : ce que les serveurs ont envoyé et que personne n'a
 * encore sorti. C'est elle qui garantit qu'aucun envoi n'est oublié.
 */
it('sert la file des bons à imprimer du point de vente', function () {
    $ctx = bonSalle();
    bonEnvoyer($ctx, [[bonProduit($ctx, 'Poulet'), 1]]);
    bonEnvoyer($ctx, [[bonProduit($ctx, 'Fondant'), 1]]);
    BonCuisine::query()->where('numero', 1)->update(['imprime_le' => now(), 'nombre_impressions' => 1]);

    getJson("/api/points-de-vente/{$ctx->pdv->id}/bons-cuisine?a_imprimer=1")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.numero', 2)
        ->assertJsonPath('data.0.a_imprimer', true);
});

it('garde dans la file un bon oublié la veille', function () {
    $ctx = bonSalle();
    bonEnvoyer($ctx, [[bonProduit($ctx, 'Poulet'), 1]]);
    $this->travel(1)->days();

    getJson("/api/points-de-vente/{$ctx->pdv->id}/bons-cuisine?a_imprimer=1")
        ->assertOk()
        ->assertJsonCount(1, 'data');

    getJson("/api/points-de-vente/{$ctx->pdv->id}/bons-cuisine")
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('imprime toute la file et la vide', function () {
    $ctx = bonSalle();
    bonEnvoyer($ctx, [[bonProduit($ctx, 'Poulet'), 1]]);
    bonEnvoyer($ctx, [[bonProduit($ctx, 'Fondant'), 1]]);

    getJson("/api/points-de-vente/{$ctx->pdv->id}/bons-cuisine/pdf")
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');

    expect(BonCuisine::query()->whereNull('imprime_le')->count())->toBe(0)
        ->and(BonCuisine::query()->pluck('nombre_impressions')->unique()->all())->toBe([1]);
});

/**
 * Deux clics sur « imprimer tout » ne sortent pas deux fois la même file : le
 * second ne trouve plus rien.
 */
it('répond 409 quand la file est vide', function () {
    $ctx = bonSalle();
    bonEnvoyer($ctx, [[bonProduit($ctx, 'Poulet'), 1]]);
    getJson("/api/points-de-vente/{$ctx->pdv->id}/bons-cuisine/pdf")->assertOk();

    getJson("/api/points-de-vente/{$ctx->pdv->id}/bons-cuisine/pdf")->assertStatus(409);
});

it('imprime des bons précis sans toucher au reste de la file', function () {
    $ctx = bonSalle();
    bonEnvoyer($ctx, [[bonProduit($ctx, 'Poulet'), 1]]);
    bonEnvoyer($ctx, [[bonProduit($ctx, 'Fondant'), 1]]);
    $premier = BonCuisine::query()->where('numero', 1)->sole();

    getJson("/api/points-de-vente/{$ctx->pdv->id}/bons-cuisine/pdf?bons[]={$premier->id}")->assertOk();

    expect($premier->fresh()->imprime_le)->not->toBeNull()
        ->and(BonCuisine::query()->where('numero', 2)->sole()->imprime_le)->toBeNull();
});

it('refuse d\'imprimer le bon d\'un autre point de vente (422)', function () {
    $ctx = bonSalle();
    $etranger = BonCuisine::factory()->create([
        'point_de_vente_id' => PointDeVente::factory()->create(['site_id' => $ctx->site->id])->id,
    ]);

    getJson("/api/points-de-vente/{$ctx->pdv->id}/bons-cuisine/pdf?bons[]={$etranger->id}")
        ->assertStatus(422)
        ->assertJsonValidationErrors('bons');
});

it('réimprime un bon en duplicata', function () {
    $ctx = bonSalle();
    bonEnvoyer($ctx, [[bonProduit($ctx, 'Poulet'), 1]]);
    $bon = BonCuisine::query()->sole();

    getJson("/api/bons-cuisine/{$bon->id}/pdf")->assertOk()->assertHeader('content-type', 'application/pdf');
    $premiereFois = $bon->fresh()->imprime_le;

    $this->travel(5)->minutes();
    getJson("/api/bons-cuisine/{$bon->id}/pdf")->assertOk();

    $bon->refresh();
    expect($bon->nombre_impressions)->toBe(2)
        ->and($bon->imprime_le->equalTo($premiereFois))->toBeTrue();
});

it('refuse l\'impression d\'un bon hors périmètre', function () {
    $ctx = bonSalle();
    bonEnvoyer($ctx, [[bonProduit($ctx, 'Poulet'), 1]]);
    $bon = BonCuisine::query()->sole();

    $ailleurs = User::factory()->create();
    $ailleurs->givePermissionTo('commande_client.view');
    $ailleurs->sites()->attach(Site::factory()->create()->id);
    Sanctum::actingAs($ailleurs);

    getJson("/api/bons-cuisine/{$bon->id}/pdf")->assertForbidden();
    getJson("/api/points-de-vente/{$ctx->pdv->id}/bons-cuisine/pdf")->assertForbidden();

    expect($bon->fresh()->imprime_le)->toBeNull();
});

// ───────────────────────── gabarit 80mm ─────────────────────────

it('met le poste en tête, la table, le numéro et les remarques, sans prix', function () {
    $ctx = bonSalle();
    $plats = CategorieProduit::factory()->create();
    bonPoste($ctx, 'Chaud', [$plats], ['par_defaut' => true]);
    bonEnvoyer($ctx, [[bonProduit($ctx, 'Poulet braisé', $plats), 2, 'sans piment']]);
    $bon = BonCuisine::query()->with(['lignes', 'commande.user'])->sole();

    $html = app(PdfService::class)->htmlBonsCuisine(collect([$bon]));

    expect($html)
        ->toContain('Chaud')
        ->toContain('Table '.$ctx->table->numero)
        ->toContain('N° 1')
        ->toContain('Poulet braisé')
        ->toContain('sans piment')
        ->toContain('Kouassi A.')
        ->not->toContain('DUPLICATA')
        ->not->toContain('5 000')
        ->not->toContain('5000');
});

it('signale l\'ajout, l\'annulation et le duplicata', function () {
    $ctx = bonSalle();
    bonEnvoyer($ctx, [[bonProduit($ctx, 'Poulet'), 1]]);
    bonEnvoyer($ctx, [[bonProduit($ctx, 'Fondant'), 1]]);
    $ligne = LigneCommandeClient::query()->orderBy('id')->first();
    deleteJson("/api/commandes-client/{$ctx->commande->id}/lignes/{$ligne->id}", ['motif' => 'erreur'])->assertOk();

    $bons = BonCuisine::query()->with(['lignes', 'commande.user'])->orderBy('numero')->get();
    $html = app(PdfService::class)->htmlBonsCuisine($bons, [$bons[0]->id => true]);

    expect($html)
        ->toContain('*** AJOUT ***')
        ->toContain('*** ANNULATION ***')
        ->toContain('DUPLICATA');
});

it('indique au poste la part de la table qu\'il prépare', function () {
    $ctx = bonSalle();
    $entrees = CategorieProduit::factory()->create();
    $plats = CategorieProduit::factory()->create();
    bonPoste($ctx, 'Froid', [$entrees], ['ordre' => 1, 'par_defaut' => true]);
    bonPoste($ctx, 'Chaud', [$plats], ['ordre' => 2]);
    bonEnvoyer($ctx, [
        [bonProduit($ctx, 'Salade', $entrees), 1],
        [bonProduit($ctx, 'Poulet', $plats), 1],
    ]);

    $html = app(PdfService::class)->htmlBonsCuisine(BonCuisine::query()->with(['lignes', 'commande.user'])->orderBy('numero')->get());

    expect($html)->toContain('Bon 1/2')->toContain('Bon 2/2');
});
