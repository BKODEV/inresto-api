<?php

declare(strict_types=1);

use App\Enums\StatutSessionCaisse;
use App\Enums\StatutTable;
use App\Models\Annulation;
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
use App\Models\ModeDePaiement;
use App\Models\MouvementStock;
use App\Models\Paiement;
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

use function Pest\Laravel\deleteJson;
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

/**
 * Le scénario qui motive tout le modèle par tours : le client commande un
 * dessert après les plats. L'ancien garde-fou refusait tout ajout dès l'envoi
 * en cuisine, il n'existait donc aucun chemin pour ce cas — la note était figée
 * pour le reste du repas.
 */
it('accepte un ajout après l\'envoi en cuisine (le dessert)', function () {
    $ctx = salleContext();
    $user = actingAsServeur($ctx->site);
    $produit = produitSurCarte($ctx);
    $commande = commandeSur($ctx, $user, 'envoyee_en_cuisine');

    postJson("/api/commandes-client/{$commande->id}/lignes", [
        'produit_id' => $produit->id,
        'quantite' => 1,
    ])->assertCreated()
        // La ligne naît au brouillon : elle n'ira en cuisine qu'au prochain envoi.
        ->assertJsonPath('data.envoyee_le', null);
});

it('refuse l\'ajout de ligne sur une note close (409)', function (string $statut) {
    $ctx = salleContext();
    $user = actingAsServeur($ctx->site);
    $produit = produitSurCarte($ctx);
    $commande = commandeSur($ctx, $user, $statut);

    postJson("/api/commandes-client/{$commande->id}/lignes", [
        'produit_id' => $produit->id,
        'quantite' => 1,
    ])->assertStatus(409);
})->with(['encaissée' => ['encaissee'], 'annulée' => ['annulee']]);

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

// ───────────────────────── tours & panier ─────────────────────────

/**
 * Le panier de la prise de commande part en un seul appel : le serveur en salle
 * ne doit pas subir un aller-retour réseau par plat.
 */
it('crée et envoie tout le panier en un seul appel', function () {
    $ctx = salleContext();
    $user = actingAsServeur($ctx->site);
    $produit = produitSurCarte($ctx, 1500);
    $commande = commandeSur($ctx, $user);

    postJson("/api/commandes-client/{$commande->id}/envoyer-en-cuisine", [
        'lignes' => [
            ['produit_id' => $produit->id, 'quantite' => 2],
            ['produit_id' => $produit->id, 'quantite' => 1, 'commentaire' => 'sans piment'],
        ],
    ])->assertOk()->assertJsonPath('data.statut', 'envoyee_en_cuisine');

    $lignes = LigneCommandeClient::where('commande_client_id', $commande->id)->get();

    expect($lignes)->toHaveCount(2)
        // Prix figé depuis la carte active, jamais envoyé par le client.
        ->and((float) $lignes->first()->prix_unitaire)->toBe(1500.0)
        ->and($commande->fresh()->montant_total)->toBe('4500.00');
});

/**
 * L'horodatage identifie le tour : sans valeur commune, la cuisine ne pourrait
 * pas regrouper un ticket.
 */
it('donne le même horodatage à toutes les lignes d\'un même tour', function () {
    $ctx = salleContext();
    $user = actingAsServeur($ctx->site);
    $produit = produitSurCarte($ctx);
    $commande = commandeSur($ctx, $user);

    postJson("/api/commandes-client/{$commande->id}/envoyer-en-cuisine", [
        'lignes' => [
            ['produit_id' => $produit->id, 'quantite' => 1],
            ['produit_id' => $produit->id, 'quantite' => 1],
        ],
    ])->assertOk();

    $tours = LigneCommandeClient::where('commande_client_id', $commande->id)
        ->pluck('envoyee_le')
        ->unique();

    expect($tours)->toHaveCount(1);
});

it('envoie un second tour sans toucher au premier', function () {
    $ctx = salleContext();
    $user = actingAsServeur($ctx->site);
    $produit = produitSurCarte($ctx);
    $commande = commandeSur($ctx, $user);

    postJson("/api/commandes-client/{$commande->id}/envoyer-en-cuisine", [
        'lignes' => [['produit_id' => $produit->id, 'quantite' => 1]],
    ])->assertOk();
    $premierTour = LigneCommandeClient::where('commande_client_id', $commande->id)->first()->envoyee_le;

    // L'horodatage du tour est à la seconde : en salle, deux services sont
    // séparés de plusieurs minutes. Sans avancer l'horloge, les deux envois du
    // test tomberaient dans la même seconde et ne formeraient qu'un tour.
    test()->travel(2)->seconds();

    postJson("/api/commandes-client/{$commande->id}/envoyer-en-cuisine", [
        'lignes' => [['produit_id' => $produit->id, 'quantite' => 1]],
    ])->assertOk();

    $tours = LigneCommandeClient::where('commande_client_id', $commande->id)->get();

    expect($tours)->toHaveCount(2)
        ->and($tours->first()->envoyee_le->eq($premierTour))->toBeTrue()
        ->and($tours->pluck('envoyee_le')->unique())->toHaveCount(2);
});

it('refuse un envoi sans rien à envoyer (422)', function () {
    $ctx = salleContext();
    $user = actingAsServeur($ctx->site);
    $commande = commandeSur($ctx, $user);

    postJson("/api/commandes-client/{$commande->id}/envoyer-en-cuisine", ['lignes' => []])
        ->assertStatus(422);
});

/**
 * Le garde-fou qui rend le multi-tour sûr. `servir()` déstockait toutes les
 * lignes actives de la note : servir le dessert aurait redéstocké les plats, et
 * la dérive ne serait apparue qu'à l'inventaire.
 */
it('ne redéstocke pas un tour déjà servi', function () {
    $ctx = salleContext();
    $user = actingAsServeur($ctx->site);
    $produit = produitSurCarte($ctx);
    $matiere = MatierePremiere::factory()->create();
    $fiche = FicheTechnique::factory()->create(['produit_id' => $produit->id]);
    LigneFicheTechnique::factory()->create([
        'fiche_technique_id' => $fiche->id,
        'matiere_premiere_id' => $matiere->id,
        'quantite' => 1,
    ]);
    $commande = commandeSur($ctx, $user);

    // Tour 1 : une portion, envoyée puis servie.
    postJson("/api/commandes-client/{$commande->id}/envoyer-en-cuisine", [
        'lignes' => [['produit_id' => $produit->id, 'quantite' => 1]],
    ])->assertOk();
    postJson("/api/commandes-client/{$commande->id}/servir")->assertOk();

    // Tour 2 : une seconde portion.
    postJson("/api/commandes-client/{$commande->id}/envoyer-en-cuisine", [
        'lignes' => [['produit_id' => $produit->id, 'quantite' => 1]],
    ])->assertOk();
    postJson("/api/commandes-client/{$commande->id}/servir")->assertOk();

    // Deux sorties d'une unité, et non trois : le tour 1 n'est pas repris.
    $sorties = MouvementStock::where('matiere_premiere_id', $matiere->id)
        ->where('type', 'sortie_vente')
        ->get();

    expect($sorties)->toHaveCount(2)
        ->and($sorties->sum(fn ($m): float => (float) $m->quantite))->toBe(2.0);
});

/**
 * La cuisine valide un ticket, pas la note. Deux tours peuvent être en
 * production en même temps ; servir le premier ne doit pas emporter le second.
 */
it('sert un tour précis sans emporter l\'autre', function () {
    $ctx = salleContext();
    $user = actingAsServeur($ctx->site);
    $produit = produitSurCarte($ctx);
    $commande = commandeSur($ctx, $user);

    postJson("/api/commandes-client/{$commande->id}/envoyer-en-cuisine", [
        'lignes' => [['produit_id' => $produit->id, 'quantite' => 1]],
    ])->assertOk();
    $premier = LigneCommandeClient::where('commande_client_id', $commande->id)->first();

    test()->travel(2)->seconds();

    postJson("/api/commandes-client/{$commande->id}/envoyer-en-cuisine", [
        'lignes' => [['produit_id' => $produit->id, 'quantite' => 1]],
    ])->assertOk();
    $second = LigneCommandeClient::where('commande_client_id', $commande->id)
        ->where('id', '!=', $premier->id)
        ->first();

    postJson("/api/commandes-client/{$commande->id}/servir", ['lignes' => [$premier->id]])
        ->assertOk();

    expect($premier->fresh()->servie_le)->not->toBeNull()
        ->and($second->fresh()->servie_le)->toBeNull()
        // La note reste « envoyée » : il reste un tour en production.
        ->and($commande->fresh()->statut->value)->toBe('envoyee_en_cuisine');
});

it('refuse de servir quand rien n\'est en production (409)', function () {
    $ctx = salleContext();
    $user = actingAsServeur($ctx->site);
    $produit = produitSurCarte($ctx);
    $commande = commandeSur($ctx, $user);
    LigneCommandeClient::factory()->create(['commande_client_id' => $commande->id, 'produit_id' => $produit->id]);

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
    $ligne = LigneCommandeClient::factory()->envoyee()->create([
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
    LigneCommandeClient::factory()->envoyee()->create(['commande_client_id' => $commande->id, 'produit_id' => $produit->id, 'quantite' => 1]);

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
    LigneCommandeClient::factory()->envoyee()->create(['commande_client_id' => $commande->id, 'produit_id' => $produit->id, 'quantite' => 3]);

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
    LigneCommandeClient::factory()->envoyee()->create(['commande_client_id' => $commande->id, 'produit_id' => $produit->id, 'quantite' => 1]);

    postJson("/api/commandes-client/{$commande->id}/servir")
        ->assertOk()
        ->assertJsonPath('data.statut', 'servie');

    expect(MouvementStock::count())->toBe(0);
    Log::shouldHaveReceived('warning')->withArgs(fn (string $message): bool => $message === 'DESTOCKAGE_MANQUANT');
});

// ───────────────────────── KDS ─────────────────────────

it('KDS : ne remonte que les lignes en production du PDV', function () {
    $ctx = salleContext();
    $user = actingAsServeur($ctx->site);
    $produit = produitSurCarte($ctx);

    $enProduction = commandeSur($ctx, $user, 'envoyee_en_cuisine');
    LigneCommandeClient::factory()->envoyee()->create(['commande_client_id' => $enProduction->id, 'produit_id' => $produit->id]);

    // Brouillon : jamais envoyé, la cuisine ne doit pas le voir.
    $brouillon = commandeSur($ctx, $user, 'ouverte');
    LigneCommandeClient::factory()->create(['commande_client_id' => $brouillon->id, 'produit_id' => $produit->id]);

    // Déjà servie : sortie de la file.
    $servie = commandeSur($ctx, $user, 'servie');
    LigneCommandeClient::factory()->servie()->create(['commande_client_id' => $servie->id, 'produit_id' => $produit->id]);

    // Envoyée sur un AUTRE point de vente.
    $autrePdv = PointDeVente::factory()->create(['site_id' => $ctx->site->id]);
    $autreTable = TableSalle::factory()->create(['point_de_vente_id' => $autrePdv->id]);
    $ailleurs = CommandeClient::factory()->envoyeeEnCuisine()->create([
        'table_id' => $autreTable->id,
        'session_caisse_id' => $ctx->session->id,
        'user_id' => $user->id,
    ]);
    LigneCommandeClient::factory()->envoyee()->create(['commande_client_id' => $ailleurs->id, 'produit_id' => $produit->id]);

    getJson("/api/kds/{$ctx->pdv->id}")
        ->assertOk()
        ->assertJsonCount(1, 'data.tours')
        ->assertJsonPath('data.tours.0.commande_id', $enProduction->id)
        ->assertJsonCount(1, 'data.tours.0.lignes');
});

/**
 * Ce que le filtre par statut de commande rendait impossible : la note est déjà
 * servie, le client commande un dessert, la cuisine doit le recevoir comme un
 * ticket distinct.
 */
it('KDS : un second tour apparaît même sur une note déjà servie', function () {
    $ctx = salleContext();
    $user = actingAsServeur($ctx->site);
    $produit = produitSurCarte($ctx);
    $commande = commandeSur($ctx, $user, 'servie');
    LigneCommandeClient::factory()->servie()->create(['commande_client_id' => $commande->id, 'produit_id' => $produit->id]);

    postJson("/api/commandes-client/{$commande->id}/envoyer-en-cuisine", [
        'lignes' => [['produit_id' => $produit->id, 'quantite' => 2]],
    ])->assertOk();

    getJson("/api/kds/{$ctx->pdv->id}")
        ->assertOk()
        // Un seul tour : le premier est servi, seul le dessert reste à produire.
        ->assertJsonCount(1, 'data.tours')
        ->assertJsonCount(1, 'data.tours.0.lignes')
        ->assertJsonPath('data.tours.0.lignes.0.quantite', 2);
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

// ───────────────────────── abandon d'une commande vierge ─────────────────────────

/**
 * Taper une table crée aussitôt sa commande, ce qui réserve la table. Défaire
 * ce geste ne doit rien coûter : sans cela, chaque clic malheureux écrivait une
 * annulation motivée et noyait la trace qui sert à détecter les fraudes.
 */
it('libère une table dont la commande n\'a jamais rien porté', function () {
    $ctx = salleContext();
    actingAsServeur($ctx->site);

    $id = postJson('/api/commandes-client', [
        'table_id' => $ctx->table->id,
        'session_caisse_id' => $ctx->session->id,
    ])->assertCreated()->json('data.id');

    expect($ctx->table->fresh()->statut)->toBe(StatutTable::Occupee);

    deleteJson("/api/commandes-client/{$id}")->assertOk();

    expect(CommandeClient::find($id))->toBeNull()
        ->and($ctx->table->fresh()->statut)->toBe(StatutTable::Libre)
        // Aucune trace d'annulation : il ne s'est rien passé.
        ->and(Annulation::count())->toBe(0);
});

it('refuse de supprimer une commande qui a porté une ligne (409)', function () {
    $ctx = salleContext();
    $user = actingAsServeur($ctx->site);
    $commande = commandeSur($ctx, $user);
    LigneCommandeClient::factory()->create([
        'commande_client_id' => $commande->id,
        'produit_id' => Produit::factory()->create()->id,
    ]);

    deleteJson("/api/commandes-client/{$commande->id}")->assertStatus(409);

    expect(CommandeClient::find($commande->id))->not->toBeNull();
});

/**
 * Une commande dont toutes les lignes ont été annulées porte déjà des
 * `annulations` : elle a une histoire, la suppression sèche l'effacerait.
 */
it('refuse de supprimer une commande dont les lignes ont été annulées (409)', function () {
    $ctx = salleContext();
    $user = actingAsServeur($ctx->site);
    $commande = commandeSur($ctx, $user);
    LigneCommandeClient::factory()->create([
        'commande_client_id' => $commande->id,
        'produit_id' => Produit::factory()->create()->id,
        'statut' => 'inactif',
    ]);

    deleteJson("/api/commandes-client/{$commande->id}")->assertStatus(409);
});

it('refuse de supprimer une commande déjà envoyée en cuisine (409)', function () {
    $ctx = salleContext();
    $user = actingAsServeur($ctx->site);
    $commande = commandeSur($ctx, $user, 'envoyee_en_cuisine');

    deleteJson("/api/commandes-client/{$commande->id}")->assertStatus(409);
});

it('expose si la commande peut être libérée', function () {
    $ctx = salleContext();
    $user = actingAsServeur($ctx->site);
    $commande = commandeSur($ctx, $user);

    getJson("/api/commandes-client/{$commande->id}")
        ->assertOk()
        ->assertJsonPath('data.peut_etre_liberee', true);

    LigneCommandeClient::factory()->create([
        'commande_client_id' => $commande->id,
        'produit_id' => Produit::factory()->create()->id,
    ]);

    getJson("/api/commandes-client/{$commande->id}")
        ->assertOk()
        ->assertJsonPath('data.peut_etre_liberee', false);
});

/**
 * Le poste de caisse a besoin de savoir de quel serveur relève chaque note :
 * quand celui-ci vient verser, le caissier filtre sur lui pour solder ses
 * tables.
 */
it('expose le serveur qui a ouvert la note', function () {
    $ctx = salleContext();
    $user = actingAsServeur($ctx->site);
    $commande = commandeSur($ctx, $user);

    getJson("/api/commandes-client/{$commande->id}")
        ->assertOk()
        ->assertJsonPath('data.serveur', trim($user->prenom.' '.$user->nom));

    getJson('/api/commandes-client?statut[]=ouverte')
        ->assertOk()
        ->assertJsonPath('data.commandes.0.serveur', trim($user->prenom.' '.$user->nom));
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
 * La liste des ventes doit dire par quel mode chaque note a été réglée. Les
 * paiements restent hors du chargement par défaut : le plan de salle se
 * rafraîchit toutes les vingt secondes et n'a que faire de l'argent.
 */
it('ne charge les paiements que sur demande', function () {
    $ctx = salleContext();
    $user = actingAsServeur($ctx->site, [
        'commande_client.view', 'commande_client.create', 'session_caisse.utiliser', 'paiement.view',
    ]);
    $commande = commandeSur($ctx, $user, 'servie');
    $mode = ModeDePaiement::factory()->create(['libelle' => 'Espèces']);
    Paiement::factory()->create([
        'commande_client_id' => $commande->id,
        'mode_de_paiement_id' => $mode->id,
        'user_id' => $user->id,
        'montant' => 40,
    ]);

    getJson('/api/commandes-client?statut[]=servie')
        ->assertOk()
        ->assertJsonMissingPath('data.commandes.0.paiements');

    getJson('/api/commandes-client?statut[]=servie&avec_paiements=1')
        ->assertOk()
        ->assertJsonPath('data.commandes.0.paiements.0.mode_de_paiement.libelle', 'Espèces');
});

it('refuse de charger les paiements sans le droit de voir l\'argent', function () {
    $ctx = salleContext();
    // Permissions du serveur : il lit ses commandes, jamais le tiroir.
    $user = actingAsServeur($ctx->site);
    commandeSur($ctx, $user, 'servie');

    getJson('/api/commandes-client?statut[]=servie&avec_paiements=1')
        ->assertStatus(422)
        ->assertJsonValidationErrors('avec_paiements');
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
    $produit = produitSurCarte($ctx);
    $commande = commandeSur($ctx, $user, 'envoyee_en_cuisine');
    LigneCommandeClient::factory()->envoyee()->create(['commande_client_id' => $commande->id, 'produit_id' => $produit->id]);
    commandeSur($ctx, $user, 'ouverte');

    getJson("/api/kds/{$ctx->pdv->id}")
        ->assertOk()
        ->assertJsonCount(1, 'data.tours');
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
