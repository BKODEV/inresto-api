<?php

declare(strict_types=1);

use App\Models\Annulation;
use App\Models\Caisse;
use App\Models\CommandeClient;
use App\Models\ModeDePaiement;
use App\Models\PointDeVente;
use App\Models\SessionCaisse;
use App\Models\Site;
use App\Models\TableSalle;
use App\Models\User;
use App\Models\Versement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;

use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

uses(RefreshDatabase::class);

/**
 * @param  string[]  $permissions
 */
function actingAsJournalUser(Site $site, array $permissions = ['journal.view']): User
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

/**
 * Salle minimale : un site, un PDV, une caisse ouverte, une table.
 */
function journalContext(): object
{
    $site = Site::factory()->create();
    $pdv = PointDeVente::factory()->create(['site_id' => $site->id]);
    $caisse = Caisse::factory()->create(['point_de_vente_id' => $pdv->id]);
    $session = SessionCaisse::factory()->create(['caisse_id' => $caisse->id]);
    $table = TableSalle::factory()->create(['point_de_vente_id' => $pdv->id]);

    return (object) compact('site', 'pdv', 'caisse', 'session', 'table');
}

function commandeJournal(object $ctx, User $user, string $statut = 'servie'): CommandeClient
{
    return CommandeClient::factory()->state(['statut' => $statut])->create([
        'table_id' => $ctx->table->id,
        'table_numero' => $ctx->table->numero,
        'session_caisse_id' => $ctx->session->id,
        'user_id' => $user->id,
    ]);
}

// ───────────────────────── lecture ─────────────────────────

it('renvoie une frise vide sur une journée sans activité', function () {
    $ctx = journalContext();
    actingAsJournalUser($ctx->site);

    getJson('/api/journal')
        ->assertOk()
        ->assertJsonPath('data.total', 0)
        ->assertJsonCount(0, 'data.evenements');
});

it('remonte un paiement avec son auteur et son montant', function () {
    $ctx = journalContext();
    $user = actingAsJournalUser($ctx->site, ['journal.view', 'paiement.create', 'session_caisse.utiliser']);
    $commande = commandeJournal($ctx, $user);
    $mode = ModeDePaiement::factory()->create(['libelle' => 'Espèces']);
    postJson("/api/commandes-client/{$commande->id}/paiements", [
        'mode_de_paiement_id' => $mode->id,
        'montant' => 2500,
        'montant_recu' => 3000,
    ])->assertCreated();

    getJson('/api/journal?type[]=paiement')
        ->assertOk()
        ->assertJsonCount(1, 'data.evenements')
        ->assertJsonPath('data.evenements.0.type', 'paiement')
        ->assertJsonPath('data.evenements.0.montant', 2500)
        ->assertJsonPath('data.evenements.0.detail', 'Espèces')
        ->assertJsonPath('data.evenements.0.auteur', trim($user->prenom.' '.$user->nom));
});

/**
 * Le cas qui justifie de journaliser la clôture à part des paiements : une note
 * soldée sans aucun paiement n'apparaîtrait dans aucune autre source.
 */
it('remonte la clôture d\'une note même sans paiement', function () {
    $ctx = journalContext();
    $user = actingAsJournalUser($ctx->site);
    $commande = commandeJournal($ctx, $user, 'encaissee');
    $commande->encaisse_par = $user->id;
    $commande->encaisse_le = now();
    $commande->save();

    getJson('/api/journal?type[]=encaissement')
        ->assertOk()
        ->assertJsonCount(1, 'data.evenements')
        ->assertJsonPath('data.evenements.0.type', 'encaissement')
        ->assertJsonPath('data.evenements.0.auteur', trim($user->prenom.' '.$user->nom));
});

/**
 * La réimpression d'une note est un vecteur de fraude : le document ressort,
 * l'encaissement non.
 */
it('trace chaque impression de facture', function () {
    $ctx = journalContext();
    $user = actingAsJournalUser($ctx->site, ['journal.view', 'commande_client.view']);
    $commande = commandeJournal($ctx, $user);

    getJson("/api/commandes-client/{$commande->id}/pdf")->assertOk();
    getJson("/api/commandes-client/{$commande->id}/pdf")->assertOk();

    getJson('/api/journal?type[]=impression')
        ->assertOk()
        ->assertJsonCount(2, 'data.evenements')
        ->assertJsonPath('data.evenements.0.type', 'impression');
});

it('trie les événements du plus récent au plus ancien, toutes sources confondues', function () {
    $ctx = journalContext();
    $user = actingAsJournalUser($ctx->site, ['journal.view', 'commande_client.view']);
    $commande = commandeJournal($ctx, $user);

    $commande->encaisse_par = $user->id;
    $commande->encaisse_le = now()->subHour();
    $commande->save();

    getJson("/api/commandes-client/{$commande->id}/pdf")->assertOk();

    $reponse = getJson('/api/journal')->assertOk();

    expect($reponse->json('data.evenements.0.type'))->toBe('impression')
        ->and($reponse->json('data.evenements.1.type'))->toBe('encaissement');
});

// ───────────────────────── bornes ─────────────────────────

it('se limite à la journée courante par défaut', function () {
    $ctx = journalContext();
    $user = actingAsJournalUser($ctx->site);
    $commande = commandeJournal($ctx, $user, 'encaissee');
    $commande->encaisse_par = $user->id;
    $commande->encaisse_le = now()->subDays(3);
    $commande->save();

    getJson('/api/journal')->assertOk()->assertJsonPath('data.total', 0);

    $debut = now()->subDays(5)->toDateString();
    getJson("/api/journal?date_debut={$debut}")->assertOk()->assertJsonPath('data.total', 1);
});

it('exclut l\'activité des sites hors périmètre', function () {
    $ctx = journalContext();
    $user = actingAsJournalUser($ctx->site);

    // Commande clôturée sur un site auquel l'utilisateur n'est pas rattaché.
    $autre = journalContext();
    $commande = commandeJournal($autre, $user, 'encaissee');
    $commande->encaisse_par = $user->id;
    $commande->encaisse_le = now();
    $commande->save();

    getJson('/api/journal')->assertOk()->assertJsonPath('data.total', 0);
});

it('refuse un type d\'événement inconnu (422)', function () {
    $ctx = journalContext();
    actingAsJournalUser($ctx->site);

    getJson('/api/journal?type[]=inventé')
        ->assertUnprocessable()
        ->assertJsonValidationErrors('type.0');
});

it('refuse le journal sans la permission journal.view', function () {
    $ctx = journalContext();
    actingAsJournalUser($ctx->site, ['commande_client.view']);

    getJson('/api/journal')->assertForbidden();
});

it('refuse le journal sans authentification', function () {
    getJson('/api/journal')->assertUnauthorized();
});

// ───────────────────────── annulations ─────────────────────────

/**
 * `sort` est une colonne texte, pas un enum. Le journal la lisait en
 * `$a->sort?->value` : sur une chaîne, PHP émet un warning et rend null. Le
 * devenir du stock ne s'affichait donc JAMAIS, alors que c'est précisément le
 * contexte métier que le journal promet de relayer — une ligne annulée après
 * service est repartie en stock, ou elle est passée en perte.
 */
it('dit le devenir du stock d\'une ligne annulée après service', function () {
    $ctx = journalContext();
    $user = actingAsJournalUser($ctx->site);
    $commande = commandeJournal($ctx, $user);

    Annulation::factory()->create([
        'annulable_type' => 'commandes_client',
        'annulable_id' => $commande->id,
        'user_id' => $user->id,
        'sort' => 'perte',
        'date_heure' => now(),
    ]);

    getJson('/api/journal?type[]=annulation')
        ->assertOk()
        ->assertJsonCount(1, 'data.evenements')
        ->assertJsonPath('data.evenements.0.detail', 'perte');
});

it('laisse le devenir vide quand rien n\'avait été déstocké', function () {
    // Une commande annulée avant d'être servie ne rend rien au stock : il n'y a
    // pas de sort à afficher, et surtout pas un « perte » par défaut.
    $ctx = journalContext();
    $user = actingAsJournalUser($ctx->site);
    $commande = commandeJournal($ctx, $user, 'ouverte');

    Annulation::factory()->create([
        'annulable_type' => 'commandes_client',
        'annulable_id' => $commande->id,
        'user_id' => $user->id,
        'sort' => null,
        'date_heure' => now(),
    ]);

    getJson('/api/journal?type[]=annulation')
        ->assertOk()
        ->assertJsonPath('data.evenements.0.detail', null);
});

// ───────────────────────── versements ─────────────────────────

/**
 * `versement` est une des six sources assemblées par l'API et remontait déjà
 * sans filtre. Ce test fige le type comme filtrable : le front s'en sert pour
 * proposer la bascule « Versements ».
 */
it('filtre sur les seules sorties de tiroir', function () {
    $ctx = journalContext();
    $user = actingAsJournalUser($ctx->site);

    Versement::factory()->create([
        'caisse_id' => $ctx->caisse->id,
        'session_caisse_id' => $ctx->session->id,
        'user_id' => $user->id,
        'montant' => 50000,
        'date_heure' => now(),
    ]);

    getJson('/api/journal?type[]=versement')
        ->assertOk()
        ->assertJsonCount(1, 'data.evenements')
        ->assertJsonPath('data.evenements.0.type', 'versement')
        ->assertJsonPath('data.evenements.0.montant', 50000);
});
