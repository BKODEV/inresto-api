<?php

declare(strict_types=1);

use App\Models\LigneCommandeClient;
use App\Models\PointDeVente;
use App\Models\SessionCuisine;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;

use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

uses(RefreshDatabase::class);

/**
 * @param  string[]  $permissions
 */
function actingAsBrigade(Site $site, array $permissions = ['session_cuisine.view', 'session_cuisine.ouvrir', 'session_cuisine.fermer']): User
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

// ───────────────────────── ouverture ─────────────────────────

it('ouvre un service et y inscrit qui l\'a ouvert', function () {
    // Sans cela, un service tenu par le seul chef sortirait avec une brigade
    // vide, et la production ne serait imputable à personne.
    $site = Site::factory()->create();
    $pdv = PointDeVente::factory()->create(['site_id' => $site->id]);
    $chef = actingAsBrigade($site);

    postJson('/api/sessions-cuisine', [
        'point_de_vente_id' => $pdv->id,
        'libelle' => 'Midi',
    ])
        ->assertCreated()
        ->assertJsonPath('data.statut', 'ouverte')
        ->assertJsonPath('data.libelle', 'Midi')
        ->assertJsonCount(1, 'data.brigade')
        ->assertJsonPath('data.brigade.0.id', $chef->id);
});

it('enregistre la brigade déclarée, sans oublier celui qui ouvre', function () {
    $site = Site::factory()->create();
    $pdv = PointDeVente::factory()->create(['site_id' => $site->id]);
    $chef = actingAsBrigade($site);
    $commis = User::factory()->create();

    $reponse = postJson('/api/sessions-cuisine', [
        'point_de_vente_id' => $pdv->id,
        'brigade' => [$commis->id],
    ])->assertCreated();

    expect($reponse->json('data.brigade.*.id'))
        ->toHaveCount(2)
        ->toContain($chef->id)
        ->toContain($commis->id);
});

/**
 * Même règle que pour la caisse, et pour la même raison : deux services ouverts
 * rendraient le rattachement des lignes ambigu.
 */
it('refuse un second service ouvert sur le même point de vente (409)', function () {
    $site = Site::factory()->create();
    $pdv = PointDeVente::factory()->create(['site_id' => $site->id]);
    actingAsBrigade($site);
    SessionCuisine::factory()->create(['point_de_vente_id' => $pdv->id]);

    postJson('/api/sessions-cuisine', ['point_de_vente_id' => $pdv->id])
        ->assertStatus(409);
});

it('laisse deux points de vente ouvrir chacun le sien', function () {
    // Une cuisine par point de vente : le Lounge et le Restaurant tournent en
    // parallèle et n'ont pas la même brigade.
    $site = Site::factory()->create();
    $bar = PointDeVente::factory()->create(['site_id' => $site->id]);
    $resto = PointDeVente::factory()->create(['site_id' => $site->id]);
    actingAsBrigade($site);

    postJson('/api/sessions-cuisine', ['point_de_vente_id' => $bar->id])->assertCreated();
    postJson('/api/sessions-cuisine', ['point_de_vente_id' => $resto->id])->assertCreated();

    expect(SessionCuisine::count())->toBe(2);
});

it('rouvre un service après la fermeture du précédent', function () {
    // L'index unique est PARTIEL : il ne contraint que les services ouverts.
    // Un index unique tout court aurait interdit le second service du jour.
    $site = Site::factory()->create();
    $pdv = PointDeVente::factory()->create(['site_id' => $site->id]);
    actingAsBrigade($site);
    SessionCuisine::factory()->fermee()->create(['point_de_vente_id' => $pdv->id]);

    postJson('/api/sessions-cuisine', ['point_de_vente_id' => $pdv->id])->assertCreated();
});

it('refuse l\'ouverture sans la permission dédiée', function () {
    $site = Site::factory()->create();
    $pdv = PointDeVente::factory()->create(['site_id' => $site->id]);
    actingAsBrigade($site, ['session_cuisine.view']);

    postJson('/api/sessions-cuisine', ['point_de_vente_id' => $pdv->id])->assertForbidden();
});

// ───────────────────────── fermeture ─────────────────────────

it('ferme un service et signe la clôture', function () {
    $site = Site::factory()->create();
    $pdv = PointDeVente::factory()->create(['site_id' => $site->id]);
    $chef = actingAsBrigade($site);
    $session = SessionCuisine::factory()->create(['point_de_vente_id' => $pdv->id]);

    postJson("/api/sessions-cuisine/{$session->id}/fermer", ['notes' => 'Service calme.'])
        ->assertOk()
        ->assertJsonPath('data.statut', 'fermee')
        ->assertJsonPath('data.ferme_par', trim($chef->prenom.' '.$chef->nom))
        ->assertJsonPath('data.notes', 'Service calme.');

    expect($session->fresh()->ferme_le)->not->toBeNull();
});

/**
 * Ouvrir et fermer sont deux droits distincts, comme pour la caisse : c'est la
 * fermeture qui fige ce que la brigade a produit.
 */
it('refuse la fermeture sans la permission dédiée', function () {
    $site = Site::factory()->create();
    $pdv = PointDeVente::factory()->create(['site_id' => $site->id]);
    actingAsBrigade($site, ['session_cuisine.view', 'session_cuisine.ouvrir']);
    $session = SessionCuisine::factory()->create(['point_de_vente_id' => $pdv->id]);

    postJson("/api/sessions-cuisine/{$session->id}/fermer")->assertForbidden();
});

it('refuse de fermer deux fois (409)', function () {
    $site = Site::factory()->create();
    $pdv = PointDeVente::factory()->create(['site_id' => $site->id]);
    actingAsBrigade($site);
    $session = SessionCuisine::factory()->fermee()->create(['point_de_vente_id' => $pdv->id]);

    postJson("/api/sessions-cuisine/{$session->id}/fermer")->assertStatus(409);
});

/**
 * Fermer avec des plats encore en production les laisserait rattachés à un
 * service clos : ils disparaîtraient de la file sans que personne ne les
 * termine.
 */
it('refuse de fermer tant que des plats ne sont pas annoncés au pass (409)', function () {
    $ctx = salleContext();
    $chef = actingAsBrigade($ctx->site);
    $session = SessionCuisine::factory()->create(['point_de_vente_id' => $ctx->pdv->id]);
    $commande = commandeSur($ctx, $chef, 'envoyee_en_cuisine');
    LigneCommandeClient::factory()->envoyee()->create([
        'commande_client_id' => $commande->id,
        'produit_id' => produitSurCarte($ctx)->id,
        'session_cuisine_id' => $session->id,
    ]);

    postJson("/api/sessions-cuisine/{$session->id}/fermer")->assertStatus(409);
});

it('ferme dès que tout est annoncé au pass', function () {
    $ctx = salleContext();
    $chef = actingAsBrigade($ctx->site);
    $session = SessionCuisine::factory()->create(['point_de_vente_id' => $ctx->pdv->id]);
    $commande = commandeSur($ctx, $chef, 'envoyee_en_cuisine');
    LigneCommandeClient::factory()->envoyee()->create([
        'commande_client_id' => $commande->id,
        'produit_id' => produitSurCarte($ctx)->id,
        'session_cuisine_id' => $session->id,
        'prete_le' => now(),
    ]);

    postJson("/api/sessions-cuisine/{$session->id}/fermer")->assertOk();
});

it('corrige la brigade à la fermeture', function () {
    // C'est à la clôture qu'on sait qui a réellement tenu le service,
    // remplacements compris.
    $site = Site::factory()->create();
    $pdv = PointDeVente::factory()->create(['site_id' => $site->id]);
    $chef = actingAsBrigade($site);
    $remplacant = User::factory()->create();
    $session = SessionCuisine::factory()->create([
        'point_de_vente_id' => $pdv->id,
        'ouvert_par' => $chef->id,
    ]);

    $reponse = postJson("/api/sessions-cuisine/{$session->id}/fermer", [
        'brigade' => [$remplacant->id],
    ])->assertOk();

    expect($reponse->json('data.brigade.*.id'))
        ->toContain($remplacant->id)
        // Qui a ouvert reste dans l'équipe : il a bien tenu le service.
        ->toContain($chef->id);
});

// ───────────────────────── rattachement des lignes ─────────────────────────

/**
 * La raison d'être du module : savoir quelle ÉQUIPE a produit quoi, là où les
 * ventes ne disent que quel serveur a vendu.
 */
it('impute au service ouvert les plats envoyés en cuisine', function () {
    $ctx = salleContext();
    $user = actingAsServeur($ctx->site, ['commande_client.create', 'commande_client.update', 'session_caisse.utiliser']);
    $session = SessionCuisine::factory()->create(['point_de_vente_id' => $ctx->pdv->id]);
    $commande = commandeSur($ctx, $user);
    $produit = produitSurCarte($ctx, 2500);

    postJson("/api/commandes-client/{$commande->id}/envoyer-en-cuisine", [
        'lignes' => [['produit_id' => $produit->id, 'quantite' => 2]],
    ])->assertOk();

    expect(LigneCommandeClient::first()->session_cuisine_id)->toBe($session->id);
});

/**
 * La cuisine ne bloque pas la salle : un service non ouvert est un oubli de la
 * brigade, pas une raison de refuser une commande. La ligne part, simplement
 * sans être imputée à une équipe.
 */
it('laisse partir un tour même sans service de cuisine ouvert', function () {
    $ctx = salleContext();
    $user = actingAsServeur($ctx->site, ['commande_client.create', 'commande_client.update', 'session_caisse.utiliser']);
    $commande = commandeSur($ctx, $user);
    $produit = produitSurCarte($ctx, 2500);

    postJson("/api/commandes-client/{$commande->id}/envoyer-en-cuisine", [
        'lignes' => [['produit_id' => $produit->id, 'quantite' => 1]],
    ])->assertOk();

    expect(LigneCommandeClient::first()->session_cuisine_id)->toBeNull();
});

it('n\'impute rien au service d\'un autre point de vente', function () {
    $ctx = salleContext();
    $autre = salleContext();
    $user = actingAsServeur($ctx->site, ['commande_client.create', 'commande_client.update', 'session_caisse.utiliser']);
    SessionCuisine::factory()->create(['point_de_vente_id' => $autre->pdv->id]);
    $commande = commandeSur($ctx, $user);

    postJson("/api/commandes-client/{$commande->id}/envoyer-en-cuisine", [
        'lignes' => [['produit_id' => produitSurCarte($ctx)->id, 'quantite' => 1]],
    ])->assertOk();

    expect(LigneCommandeClient::first()->session_cuisine_id)->toBeNull();
});

// ───────────────────────── production ─────────────────────────

it('agrège la production du service par produit', function () {
    $ctx = salleContext();
    $chef = actingAsBrigade($ctx->site);
    $session = SessionCuisine::factory()->create(['point_de_vente_id' => $ctx->pdv->id]);
    $commande = commandeSur($ctx, $chef, 'envoyee_en_cuisine');
    $poulet = produitSurCarte($ctx, 3000);
    $riz = produitSurCarte($ctx, 1000);

    LigneCommandeClient::factory()->envoyee()->create([
        'commande_client_id' => $commande->id, 'produit_id' => $poulet->id,
        'quantite' => 2, 'prix_unitaire' => 3000, 'session_cuisine_id' => $session->id,
    ]);
    LigneCommandeClient::factory()->envoyee()->create([
        'commande_client_id' => $commande->id, 'produit_id' => $riz->id,
        'quantite' => 5, 'prix_unitaire' => 1000, 'session_cuisine_id' => $session->id,
    ]);

    getJson("/api/sessions-cuisine/{$session->id}/production")
        ->assertOk()
        // Le plus produit en tête : c'est ce qu'un chef regarde en premier.
        ->assertJsonPath('data.production.0.quantite', 5)
        ->assertJsonPath('data.production.0.ca', 5000)
        ->assertJsonPath('data.production.1.quantite', 2)
        ->assertJsonPath('data.production.1.ca', 6000);
});

it('exclut de la production les lignes annulées', function () {
    // La brigade les a préparées, mais elles n'ont rien vendu : les compter
    // gonflerait le CA d'un service de plats jetés.
    $ctx = salleContext();
    $chef = actingAsBrigade($ctx->site);
    $session = SessionCuisine::factory()->create(['point_de_vente_id' => $ctx->pdv->id]);
    $commande = commandeSur($ctx, $chef, 'envoyee_en_cuisine');
    $produit = produitSurCarte($ctx, 3000);

    LigneCommandeClient::factory()->envoyee()->create([
        'commande_client_id' => $commande->id, 'produit_id' => $produit->id,
        'quantite' => 1, 'prix_unitaire' => 3000, 'session_cuisine_id' => $session->id,
        'statut' => 'inactif',
    ]);

    getJson("/api/sessions-cuisine/{$session->id}/production")
        ->assertOk()
        ->assertJsonCount(0, 'data.production');
});

// ───────────────────────── lecture ─────────────────────────

it('remonte le service ouvert en tête de liste', function () {
    // C'est celui qu'on vient consulter : le classer par date le noierait sous
    // l'historique dès le lendemain.
    $site = Site::factory()->create();
    $pdv = PointDeVente::factory()->create(['site_id' => $site->id]);
    actingAsBrigade($site);
    SessionCuisine::factory()->fermee()->create([
        'point_de_vente_id' => $pdv->id, 'ouvert_le' => now(),
    ]);
    $ouverte = SessionCuisine::factory()->create([
        'point_de_vente_id' => $pdv->id, 'ouvert_le' => now()->subDay(),
    ]);

    getJson('/api/sessions-cuisine')
        ->assertOk()
        ->assertJsonPath('data.sessions.0.id', $ouverte->id);
});

it('borne la liste au périmètre de sites', function () {
    $site = Site::factory()->create();
    $autreSite = Site::factory()->create();
    $pdv = PointDeVente::factory()->create(['site_id' => $site->id]);
    $autrePdv = PointDeVente::factory()->create(['site_id' => $autreSite->id]);
    actingAsBrigade($site);
    $mien = SessionCuisine::factory()->create(['point_de_vente_id' => $pdv->id]);
    SessionCuisine::factory()->create(['point_de_vente_id' => $autrePdv->id]);

    getJson('/api/sessions-cuisine')
        ->assertOk()
        ->assertJsonCount(1, 'data.sessions')
        ->assertJsonPath('data.sessions.0.id', $mien->id);
});

it('refuse la lecture sans la permission', function () {
    $site = Site::factory()->create();
    actingAsBrigade($site, ['commande_client.view']);

    getJson('/api/sessions-cuisine')->assertForbidden();
});
