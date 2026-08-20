<?php

declare(strict_types=1);

use App\Enums\StatutSessionCaisse;
use App\Enums\StatutTable;
use App\Models\Caisse;
use App\Models\Carte;
use App\Models\CarteProduit;
use App\Models\CommandeClient;
use App\Models\FicheTechnique;
use App\Models\LigneCommandeClient;
use App\Models\LigneFicheTechnique;
use App\Models\LigneFicheTechniqueSousRecette;
use App\Models\LigneSousRecette;
use App\Models\MatierePremiere;
use App\Models\MouvementStock;
use App\Models\PointDeVente;
use App\Models\Produit;
use App\Models\SessionCaisse;
use App\Models\Site;
use App\Models\SousRecette;
use App\Models\TableSalle;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;

use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

uses(RefreshDatabase::class);

/**
 * Contexte salle cohérent : site → PDV → caisse → session ouverte, table libre,
 * carte active sur le PDV.
 */
function salleContext(): object
{
    $site = Site::factory()->create();
    $pdv = PointDeVente::factory()->create(['site_id' => $site->id]);
    $caisse = Caisse::factory()->create(['point_de_vente_id' => $pdv->id]);
    $session = SessionCaisse::factory()->create(['caisse_id' => $caisse->id]);
    $table = TableSalle::factory()->create(['point_de_vente_id' => $pdv->id]);
    $carte = Carte::factory()->active()->create(['point_de_vente_id' => $pdv->id]);

    return (object) compact('site', 'pdv', 'caisse', 'session', 'table', 'carte');
}

/**
 * @param  string[]  $permissions
 */
function actingAsServeur(Site $site, array $permissions = ['commande_client.create', 'commande_client.update', 'commande_client.view', 'session_caisse.utiliser']): User
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

function produitSurCarte(object $ctx, float $prix = 10): Produit
{
    $produit = Produit::factory()->create();
    CarteProduit::factory()->create([
        'carte_id' => $ctx->carte->id,
        'produit_id' => $produit->id,
        'prix_vente' => $prix,
    ]);

    return $produit;
}

function commandeSur(object $ctx, User $user, string $statut = 'ouverte'): CommandeClient
{
    return CommandeClient::factory()->state(['statut' => $statut])->create([
        'table_id' => $ctx->table->id,
        'session_caisse_id' => $ctx->session->id,
        'user_id' => $user->id,
    ]);
}

// ───────────────────────── création ─────────────────────────

it('ouvre une commande : table occupee, montant_total 0', function () {
    $ctx = salleContext();
    actingAsServeur($ctx->site);

    postJson('/api/commandes-client', [
        'table_id' => $ctx->table->id,
        'session_caisse_id' => $ctx->session->id,
    ])
        ->assertCreated()
        ->assertJsonPath('data.statut', 'ouverte')
        ->assertJsonPath('data.montant_total', '0.00');

    expect($ctx->table->fresh()->statut)->toBe(StatutTable::Occupee);
});

it('refuse une commande sur une table occupee (409)', function () {
    $ctx = salleContext();
    actingAsServeur($ctx->site);
    $ctx->table->statut = StatutTable::Occupee;
    $ctx->table->save();

    postJson('/api/commandes-client', [
        'table_id' => $ctx->table->id,
        'session_caisse_id' => $ctx->session->id,
    ])->assertStatus(409);
});

/**
 * Une table archivée peut très bien être « libre » : sans ce contrôle en amont,
 * le serveur recevrait le message d'indisponibilité de statut, incompréhensible
 * pour une table qui n'est plus en salle.
 */
it('refuse une commande sur une table archivée (409)', function () {
    $ctx = salleContext();
    actingAsServeur($ctx->site);
    $ctx->table->archivee_le = now();
    $ctx->table->save();

    postJson('/api/commandes-client', [
        'table_id' => $ctx->table->id,
        'session_caisse_id' => $ctx->session->id,
    ])->assertStatus(409)
        ->assertJsonPath('message', fn (string $message) => str_contains($message, 'archivée'));
});

it('refuse une commande sur une session fermée (422)', function () {
    $ctx = salleContext();
    actingAsServeur($ctx->site);
    $ctx->session->statut = StatutSessionCaisse::Fermee;
    $ctx->session->save();

    postJson('/api/commandes-client', [
        'table_id' => $ctx->table->id,
        'session_caisse_id' => $ctx->session->id,
    ])->assertStatus(422);
});

it('refuse une session d\'un autre PDV (422)', function () {
    $ctx = salleContext();
    actingAsServeur($ctx->site);
    $autrePdv = PointDeVente::factory()->create(['site_id' => $ctx->site->id]);
    $autreCaisse = Caisse::factory()->create(['point_de_vente_id' => $autrePdv->id]);
    $autreSession = SessionCaisse::factory()->create(['caisse_id' => $autreCaisse->id]);

    postJson('/api/commandes-client', [
        'table_id' => $ctx->table->id,
        'session_caisse_id' => $autreSession->id,
    ])->assertStatus(422);
});

// ───────────────────────── lignes ─────────────────────────

it('ajoute une ligne au prix de la carte et recalcule le montant', function () {
    $ctx = salleContext();
    $user = actingAsServeur($ctx->site);
    $produit = produitSurCarte($ctx, 12.50);
    $commande = commandeSur($ctx, $user);

    postJson("/api/commandes-client/{$commande->id}/lignes", [
        'produit_id' => $produit->id,
        'quantite' => 2,
    ])
        ->assertCreated()
        ->assertJsonPath('data.prix_unitaire', '12.50');

    expect($commande->fresh()->montant_total)->toBe('25.00');
});

it('refuse un produit absent de la carte active (422)', function () {
    $ctx = salleContext();
    $user = actingAsServeur($ctx->site);
    $commande = commandeSur($ctx, $user);
    $produitHorsCarte = Produit::factory()->create();

    postJson("/api/commandes-client/{$commande->id}/lignes", [
        'produit_id' => $produitHorsCarte->id,
        'quantite' => 1,
    ])->assertStatus(422);
});

it('refuse l\'ajout de ligne sur une commande envoyée en cuisine (422)', function () {
    $ctx = salleContext();
    $user = actingAsServeur($ctx->site);
    $produit = produitSurCarte($ctx);
    $commande = commandeSur($ctx, $user, 'envoyee_en_cuisine');

    postJson("/api/commandes-client/{$commande->id}/lignes", [
        'produit_id' => $produit->id,
        'quantite' => 1,
    ])->assertStatus(422);
});

// ───────────────────────── envoi en cuisine ─────────────────────────

it('envoie une commande en cuisine', function () {
    $ctx = salleContext();
    $user = actingAsServeur($ctx->site);
    $produit = produitSurCarte($ctx);
    $commande = commandeSur($ctx, $user);
    LigneCommandeClient::factory()->create(['commande_client_id' => $commande->id, 'produit_id' => $produit->id]);

    postJson("/api/commandes-client/{$commande->id}/envoyer-en-cuisine")
        ->assertOk()
        ->assertJsonPath('data.statut', 'envoyee_en_cuisine');
});

it('refuse l\'envoi en cuisine sans ligne (422)', function () {
    $ctx = salleContext();
    $user = actingAsServeur($ctx->site);
    $commande = commandeSur($ctx, $user);

    postJson("/api/commandes-client/{$commande->id}/envoyer-en-cuisine")->assertStatus(422);
});

it('refuse une transition invalide ouverte → servie (409)', function () {
    $ctx = salleContext();
    $user = actingAsServeur($ctx->site);
    $commande = commandeSur($ctx, $user);

    postJson("/api/commandes-client/{$commande->id}/servir")->assertStatus(409);
});

// ───────────────────────── service & déstockage ─────────────────────────

it('servir : crée les mouvements de stock des matières directes et fige la fiche', function () {
    $ctx = salleContext();
    $user = actingAsServeur($ctx->site);
    $produit = produitSurCarte($ctx);
    $matiere = MatierePremiere::factory()->create();
    $fiche = FicheTechnique::factory()->create(['produit_id' => $produit->id]);
    LigneFicheTechnique::factory()->create([
        'fiche_technique_id' => $fiche->id,
        'matiere_premiere_id' => $matiere->id,
        'quantite' => 2,
    ]);

    $commande = commandeSur($ctx, $user, 'envoyee_en_cuisine');
    $ligne = LigneCommandeClient::factory()->create([
        'commande_client_id' => $commande->id,
        'produit_id' => $produit->id,
        'quantite' => 3,
    ]);

    postJson("/api/commandes-client/{$commande->id}/servir")
        ->assertOk()
        ->assertJsonPath('data.statut', 'servie');

    $mvt = MouvementStock::query()
        ->where('matiere_premiere_id', $matiere->id)
        ->where('site_id', $ctx->site->id)
        ->where('type', 'sortie_vente')
        ->first();

    expect($mvt)->not->toBeNull()
        ->and((float) $mvt->quantite)->toBe(6.0)   // 2 (fiche) × 3 (commande)
        ->and($ligne->fresh()->fiche_technique_id)->toBe($fiche->id);
});

it('servir : déstocke sur le bon site (PDV → site)', function () {
    $ctx = salleContext();
    $user = actingAsServeur($ctx->site);
    $produit = produitSurCarte($ctx);
    $matiere = MatierePremiere::factory()->create();
    $fiche = FicheTechnique::factory()->create(['produit_id' => $produit->id]);
    LigneFicheTechnique::factory()->create(['fiche_technique_id' => $fiche->id, 'matiere_premiere_id' => $matiere->id, 'quantite' => 1]);

    $commande = commandeSur($ctx, $user, 'envoyee_en_cuisine');
    LigneCommandeClient::factory()->create(['commande_client_id' => $commande->id, 'produit_id' => $produit->id, 'quantite' => 1]);

    postJson("/api/commandes-client/{$commande->id}/servir")->assertOk();

    expect(MouvementStock::where('matiere_premiere_id', $matiere->id)->where('site_id', $ctx->site->id)->exists())->toBeTrue()
        ->and(MouvementStock::where('site_id', '!=', $ctx->site->id)->exists())->toBeFalse();
});

it('servir : explose une sous-recette en matières premières au prorata', function () {
    $ctx = salleContext();
    $user = actingAsServeur($ctx->site);
    $produit = produitSurCarte($ctx);
    $matiere = MatierePremiere::factory()->create();

    // Sous-recette : produit 10 unités, contient 5 de la matière -> 0,5 / unité.
    $sousRecette = SousRecette::factory()->create(['quantite_produite' => 10]);
    LigneSousRecette::factory()->create(['sous_recette_id' => $sousRecette->id, 'matiere_premiere_id' => $matiere->id, 'quantite' => 5]);

    // Fiche : 2 unités de sous-recette ; commande : 3 portions.
    // 5/10 × 2 × 3 = 3.
    $fiche = FicheTechnique::factory()->create(['produit_id' => $produit->id]);
    LigneFicheTechniqueSousRecette::factory()->create(['fiche_technique_id' => $fiche->id, 'sous_recette_id' => $sousRecette->id, 'quantite' => 2]);

    $commande = commandeSur($ctx, $user, 'envoyee_en_cuisine');
    LigneCommandeClient::factory()->create(['commande_client_id' => $commande->id, 'produit_id' => $produit->id, 'quantite' => 3]);

    postJson("/api/commandes-client/{$commande->id}/servir")->assertOk();

    $mvt = MouvementStock::where('matiere_premiere_id', $matiere->id)->where('type', 'sortie_vente')->first();
    expect($mvt)->not->toBeNull()->and((float) $mvt->quantite)->toBe(3.0);
});

it('servir un produit sans fiche active : log, pas de blocage, commande servie', function () {
    Log::spy();
    $ctx = salleContext();
    $user = actingAsServeur($ctx->site);
    $produit = produitSurCarte($ctx); // aucune fiche technique
    $commande = commandeSur($ctx, $user, 'envoyee_en_cuisine');
    LigneCommandeClient::factory()->create(['commande_client_id' => $commande->id, 'produit_id' => $produit->id, 'quantite' => 1]);

    postJson("/api/commandes-client/{$commande->id}/servir")
        ->assertOk()
        ->assertJsonPath('data.statut', 'servie');

    expect(MouvementStock::count())->toBe(0);
    Log::shouldHaveReceived('warning')->withArgs(fn (string $message): bool => $message === 'DESTOCKAGE_MANQUANT');
});

// ───────────────────────── KDS ─────────────────────────

it('KDS : retourne uniquement les commandes envoyée_en_cuisine du PDV', function () {
    $ctx = salleContext();
    $user = actingAsServeur($ctx->site);

    commandeSur($ctx, $user, 'envoyee_en_cuisine');
    commandeSur($ctx, $user, 'ouverte');

    // Une commande envoyée sur un AUTRE PDV ne doit pas remonter.
    $autrePdv = PointDeVente::factory()->create(['site_id' => $ctx->site->id]);
    $autreTable = TableSalle::factory()->create(['point_de_vente_id' => $autrePdv->id]);
    CommandeClient::factory()->envoyeeEnCuisine()->create([
        'table_id' => $autreTable->id,
        'session_caisse_id' => $ctx->session->id,
        'user_id' => $user->id,
    ]);

    getJson("/api/kds/{$ctx->pdv->id}")
        ->assertOk()
        ->assertJsonCount(1, 'data.commandes')
        ->assertJsonPath('data.commandes.0.statut', 'envoyee_en_cuisine');
});

// ───────────────────────── permissions ─────────────────────────

it('refuse l\'accès sans authentification', function () {
    getJson('/api/commandes-client')->assertUnauthorized();
});

it('refuse la création sans la permission commande_client.create', function () {
    $ctx = salleContext();
    actingAsServeur($ctx->site, ['session_caisse.utiliser']);

    postJson('/api/commandes-client', [
        'table_id' => $ctx->table->id,
        'session_caisse_id' => $ctx->session->id,
    ])->assertForbidden();
});

it('refuse la création si l\'utilisateur n\'est pas rattaché au site (403)', function () {
    $ctx = salleContext();
    // Permissions OK mais pas d'attache au site.
    foreach (['commande_client.create', 'session_caisse.utiliser'] as $name) {
        Permission::findOrCreate($name, 'api');
    }
    $user = User::factory()->create();
    $user->givePermissionTo(['commande_client.create', 'session_caisse.utiliser']);
    Sanctum::actingAs($user);

    postJson('/api/commandes-client', [
        'table_id' => $ctx->table->id,
        'session_caisse_id' => $ctx->session->id,
    ])->assertStatus(403);
});

/**
 * Le numéro doit être copié à l'ouverture, pas relu à l'impression : c'est la
 * copie qui protège les factures d'un renommage ultérieur de la table.
 */
it('fige le numéro de table à l\'ouverture de la commande', function () {
    $ctx = salleContext();
    actingAsServeur($ctx->site);
    $ctx->table->update(['numero' => 'T07']);

    $id = postJson('/api/commandes-client', [
        'table_id' => $ctx->table->id,
        'session_caisse_id' => $ctx->session->id,
    ])->assertCreated()->json('data.id');

    $ctx->table->update(['numero' => 'T12']);

    expect(CommandeClient::find($id)->table_numero)->toBe('T07');
});

// ───────────────────────── index : filtres & pagination ─────────────────────────

it('pagine l\'index et expose le total', function () {
    $ctx = salleContext();
    $user = actingAsServeur($ctx->site);
    CommandeClient::factory()->count(7)->create([
        'table_id' => $ctx->table->id,
        'session_caisse_id' => $ctx->session->id,
        'user_id' => $user->id,
    ]);

    getJson('/api/commandes-client?per_page=3')
        ->assertOk()
        ->assertJsonCount(3, 'data.commandes')
        ->assertJsonPath('data.pagination.total', 7)
        ->assertJsonPath('data.pagination.derniere_page', 3);
});

/**
 * Le filtre porte la charge : sans lui, l'écran de prise de commande
 * retéléchargeait tout l'historique du restaurant toutes les vingt secondes.
 */
it('filtre par statuts vivants sans toucher à l\'historique', function () {
    $ctx = salleContext();
    $user = actingAsServeur($ctx->site);
    commandeSur($ctx, $user, 'ouverte');
    commandeSur($ctx, $user, 'servie');
    commandeSur($ctx, $user, 'encaissee');
    commandeSur($ctx, $user, 'annulee');

    getJson('/api/commandes-client?statut[]=ouverte&statut[]=envoyee_en_cuisine&statut[]=servie')
        ->assertOk()
        ->assertJsonCount(2, 'data.commandes');
});

it('filtre par point de vente sans charger celui d\'à côté', function () {
    $ctx = salleContext();
    $user = actingAsServeur($ctx->site);
    commandeSur($ctx, $user);

    $autrePdv = PointDeVente::factory()->create(['site_id' => $ctx->site->id]);
    $autreTable = TableSalle::factory()->create(['point_de_vente_id' => $autrePdv->id]);
    CommandeClient::factory()->create([
        'table_id' => $autreTable->id,
        'session_caisse_id' => $ctx->session->id,
        'user_id' => $user->id,
    ]);

    getJson("/api/commandes-client?point_de_vente_id={$ctx->pdv->id}")
        ->assertOk()
        ->assertJsonCount(1, 'data.commandes');
});

/**
 * La journée d'un restaurant n'est pas une journée calendaire : un service qui
 * dépasse minuit doit rester groupé. C'est la session de caisse qui la borne.
 */
it('filtre par session de caisse', function () {
    $ctx = salleContext();
    $user = actingAsServeur($ctx->site);
    commandeSur($ctx, $user);

    // Une seule session ouverte par caisse : le service précédent est clos,
    // ce qui est justement le cas d'usage — retrouver les commandes d'hier.
    // `statut` n'est pas fillable : il n'appartient qu'au service de caisse.
    $ctx->session->statut = StatutSessionCaisse::Fermee;
    $ctx->session->ferme_le = now();
    $ctx->session->save();
    $autreSession = SessionCaisse::factory()->create(['caisse_id' => $ctx->caisse->id]);
    CommandeClient::factory()->create([
        'table_id' => $ctx->table->id,
        'session_caisse_id' => $autreSession->id,
        'user_id' => $user->id,
    ]);

    getJson("/api/commandes-client?session_caisse_id={$ctx->session->id}")
        ->assertOk()
        ->assertJsonCount(1, 'data.commandes');
});

it('refuse un statut inconnu plutôt que de renvoyer une salle déserte (422)', function () {
    $ctx = salleContext();
    actingAsServeur($ctx->site);

    getJson('/api/commandes-client?statut[]=perdue')
        ->assertUnprocessable()
        ->assertJsonValidationErrors('statut.0');
});

it('borne l\'index au périmètre de sites de l\'utilisateur', function () {
    $ctx = salleContext();
    $user = actingAsServeur($ctx->site);
    commandeSur($ctx, $user);

    // Commande d'un site auquel l'utilisateur n'est pas rattaché.
    $autreSite = Site::factory()->create();
    $autrePdv = PointDeVente::factory()->create(['site_id' => $autreSite->id]);
    $autreTable = TableSalle::factory()->create(['point_de_vente_id' => $autrePdv->id]);
    CommandeClient::factory()->create([
        'table_id' => $autreTable->id,
        'session_caisse_id' => $ctx->session->id,
        'user_id' => $user->id,
    ]);

    getJson('/api/commandes-client')
        ->assertOk()
        ->assertJsonCount(1, 'data.commandes');
});

// ───────────────────────── KDS ─────────────────────────

it('sert la file cuisine du point de vente', function () {
    $ctx = salleContext();
    $user = actingAsServeur($ctx->site);
    commandeSur($ctx, $user, 'envoyee_en_cuisine');
    commandeSur($ctx, $user, 'ouverte');

    getJson("/api/kds/{$ctx->pdv->id}")
        ->assertOk()
        ->assertJsonCount(1, 'data.commandes');
});

it('refuse le KDS sans la permission de lecture des commandes', function () {
    $ctx = salleContext();
    actingAsServeur($ctx->site, ['session_caisse.utiliser']);

    getJson("/api/kds/{$ctx->pdv->id}")->assertForbidden();
});

/**
 * L'écran est vissé au mur d'une cuisine : il n'a aucune raison d'exposer la
 * file d'un autre établissement à qui connaît l'identifiant du point de vente.
 */
it('refuse le KDS d\'un point de vente hors périmètre', function () {
    $ctx = salleContext();
    actingAsServeur($ctx->site);

    $autreSite = Site::factory()->create();
    $autrePdv = PointDeVente::factory()->create(['site_id' => $autreSite->id]);

    getJson("/api/kds/{$autrePdv->id}")->assertForbidden();
});
