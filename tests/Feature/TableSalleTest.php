<?php

declare(strict_types=1);

use App\Enums\StatutCommandeClient;
use App\Models\CommandeClient;
use App\Models\PointDeVente;
use App\Models\TableSalle;
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
function actingAsTableUser(array $permissions = []): User
{
    $user = User::factory()->create();
    foreach ($permissions as $name) {
        Permission::findOrCreate($name, 'api');
    }
    $user->givePermissionTo($permissions);
    Sanctum::actingAs($user);

    return $user;
}

// ───────────────────────── CRUD ─────────────────────────

it('crée une table', function () {
    actingAsTableUser(['table.create']);
    $pdv = PointDeVente::factory()->create();

    postJson('/api/tables', [
        'point_de_vente_id' => $pdv->id,
        'numero' => 'T1',
        'capacite' => 4,
    ])
        ->assertCreated()
        ->assertJsonPath('data.numero', 'T1')
        ->assertJsonPath('data.statut', 'libre');

    expect(TableSalle::where('numero', 'T1')->where('point_de_vente_id', $pdv->id)->exists())->toBeTrue();
});

it('affiche une table', function () {
    actingAsTableUser(['table.view']);
    $table = TableSalle::factory()->create();

    getJson("/api/tables/{$table->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $table->id);
});

it('modifie une table', function () {
    actingAsTableUser(['table.update']);
    $table = TableSalle::factory()->create(['capacite' => 2]);

    patchJson("/api/tables/{$table->id}", ['capacite' => 6])
        ->assertOk()
        ->assertJsonPath('data.capacite', 6);

    expect($table->fresh()->capacite)->toBe(6);
});

it('supprime une table sans commande (200)', function () {
    actingAsTableUser(['table.delete']);
    $table = TableSalle::factory()->create();

    deleteJson("/api/tables/{$table->id}")->assertOk();

    expect(TableSalle::find($table->id))->toBeNull();
});

it('refuse de supprimer une table porteuse de commandes (409)', function (StatutCommandeClient $statut) {
    actingAsTableUser(['table.delete']);
    $table = TableSalle::factory()->create();
    CommandeClient::factory()->create(['table_id' => $table->id, 'statut' => $statut->value]);

    deleteJson("/api/tables/{$table->id}")->assertStatus(409);

    expect(TableSalle::find($table->id))->not->toBeNull();
})->with([
    'commande active' => [StatutCommandeClient::Ouverte],
    'commande historique (annulée)' => [StatutCommandeClient::Annulee],
]);

// ───────────────────────── unicité ─────────────────────────

it('refuse un numéro de table dupliqué sur le même PDV (422)', function () {
    actingAsTableUser(['table.create']);
    $pdv = PointDeVente::factory()->create();
    TableSalle::factory()->create(['point_de_vente_id' => $pdv->id, 'numero' => 'T1']);

    postJson('/api/tables', [
        'point_de_vente_id' => $pdv->id,
        'numero' => 'T1',
        'capacite' => 4,
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('numero');
});

it('autorise le même numéro sur deux PDV différents', function () {
    actingAsTableUser(['table.create']);
    $pdvA = PointDeVente::factory()->create();
    $pdvB = PointDeVente::factory()->create();
    TableSalle::factory()->create(['point_de_vente_id' => $pdvA->id, 'numero' => 'T1']);

    postJson('/api/tables', [
        'point_de_vente_id' => $pdvB->id,
        'numero' => 'T1',
        'capacite' => 4,
    ])->assertCreated();
});

// ───────────────────────── statut / filtre ─────────────────────────

it('change manuellement le statut d\'une table', function () {
    actingAsTableUser(['table.update']);
    $table = TableSalle::factory()->create();

    patchJson("/api/tables/{$table->id}/statut", ['statut' => 'a_debarrasser'])
        ->assertOk()
        ->assertJsonPath('data.statut', 'a_debarrasser');

    expect($table->fresh()->statut->value)->toBe('a_debarrasser');
});

it('filtre l\'index par ?point_de_vente_id', function () {
    actingAsTableUser(['table.view']);
    $pdvA = PointDeVente::factory()->create();
    $pdvB = PointDeVente::factory()->create();
    TableSalle::factory()->count(2)->create(['point_de_vente_id' => $pdvA->id]);
    TableSalle::factory()->create(['point_de_vente_id' => $pdvB->id]);

    getJson("/api/tables?point_de_vente_id={$pdvA->id}")
        ->assertOk()
        ->assertJsonCount(2, 'data.tables');
});

// ───────────────────────── permissions ─────────────────────────

it('refuse l\'accès sans authentification', function () {
    getJson('/api/tables')->assertUnauthorized();
});

it('refuse la création sans la permission', function () {
    actingAsTableUser([]);

    postJson('/api/tables', [
        'point_de_vente_id' => PointDeVente::factory()->create()->id,
        'numero' => 'T9',
        'capacite' => 2,
    ])->assertForbidden();
});

// ───────────────────────── archivage ─────────────────────────

it('archive une table sans commande en cours', function () {
    actingAsTableUser(['table.delete']);
    $table = TableSalle::factory()->create();

    postJson("/api/tables/{$table->id}/archiver")->assertOk();

    expect($table->fresh()->archivee_le)->not->toBeNull();
});

/**
 * Tout l'intérêt de l'archivage face à la suppression : une table qui a servi
 * ne peut plus être supprimée, mais doit pouvoir quitter la salle.
 */
it('archive une table porteuse de commandes terminées', function (StatutCommandeClient $statut) {
    actingAsTableUser(['table.delete']);
    $table = TableSalle::factory()->create();
    CommandeClient::factory()->create(['table_id' => $table->id, 'statut' => $statut->value]);

    deleteJson("/api/tables/{$table->id}")->assertStatus(409);
    postJson("/api/tables/{$table->id}/archiver")->assertOk();

    expect($table->fresh()->archivee_le)->not->toBeNull();
})->with([
    'commande encaissée' => [StatutCommandeClient::Encaissee],
    'commande annulée' => [StatutCommandeClient::Annulee],
]);

it('refuse d\'archiver une table portant une commande en cours (409)', function (StatutCommandeClient $statut) {
    actingAsTableUser(['table.delete']);
    $table = TableSalle::factory()->create();
    CommandeClient::factory()->create(['table_id' => $table->id, 'statut' => $statut->value]);

    postJson("/api/tables/{$table->id}/archiver")->assertStatus(409);

    expect($table->fresh()->archivee_le)->toBeNull();
})->with([
    'ouverte' => [StatutCommandeClient::Ouverte],
    'envoyée en cuisine' => [StatutCommandeClient::EnvoyeeEnCuisine],
    'servie mais pas encore encaissée' => [StatutCommandeClient::Servie],
]);

it('refuse d\'archiver deux fois (409)', function () {
    actingAsTableUser(['table.delete']);
    $table = TableSalle::factory()->archivee()->create();

    postJson("/api/tables/{$table->id}/archiver")->assertStatus(409);
});

it('réactive une table archivée en conservant son jeton public', function () {
    actingAsTableUser(['table.delete']);
    $table = TableSalle::factory()->archivee()->create();
    $jeton = $table->public_token;

    postJson("/api/tables/{$table->id}/reactiver")->assertOk();

    expect($table->fresh()->archivee_le)->toBeNull()
        ->and($table->fresh()->public_token)->toBe($jeton);
});

it('refuse de réactiver une table qui ne l\'est pas (409)', function () {
    actingAsTableUser(['table.delete']);
    $table = TableSalle::factory()->create();

    postJson("/api/tables/{$table->id}/reactiver")->assertStatus(409);
});

it('masque les tables archivées de l\'index, sauf sur ?archivees=1', function () {
    actingAsTableUser(['table.view']);
    $pdv = PointDeVente::factory()->create();
    TableSalle::factory()->count(2)->create(['point_de_vente_id' => $pdv->id]);
    TableSalle::factory()->archivee()->create(['point_de_vente_id' => $pdv->id]);

    getJson("/api/tables?point_de_vente_id={$pdv->id}")
        ->assertOk()
        ->assertJsonCount(2, 'data.tables');

    getJson("/api/tables?point_de_vente_id={$pdv->id}&archivees=1")
        ->assertOk()
        ->assertJsonCount(3, 'data.tables');
});

it('refuse l\'archivage sans la permission table.delete', function () {
    actingAsTableUser(['table.update']);
    $table = TableSalle::factory()->create();

    postJson("/api/tables/{$table->id}/archiver")->assertForbidden();
    postJson("/api/tables/{$table->id}/reactiver")->assertForbidden();
});

// ───────────────────────── création en lot ─────────────────────────

it('crée une série de tables en un appel', function () {
    actingAsTableUser(['table.create']);
    $pdv = PointDeVente::factory()->create();

    postJson('/api/tables/lot', [
        'point_de_vente_id' => $pdv->id,
        'lignes' => [
            ['numero' => 'T01', 'capacite' => 2],
            ['numero' => 'T02', 'capacite' => 4],
            ['numero' => 'T03', 'capacite' => 4],
        ],
    ])->assertCreated()->assertJsonCount(3, 'data');

    expect(TableSalle::where('point_de_vente_id', $pdv->id)->count())->toBe(3);
});

/**
 * Le jeton est ce qui rend la planche QR imprimable dans la foulée du lot :
 * sans lui, créer trente tables obligerait à passer par le backfill.
 */
it('dote chaque table du lot de son jeton public', function () {
    actingAsTableUser(['table.create']);
    $pdv = PointDeVente::factory()->create();

    postJson('/api/tables/lot', [
        'point_de_vente_id' => $pdv->id,
        'lignes' => [['numero' => 'T01', 'capacite' => 2], ['numero' => 'T02', 'capacite' => 2]],
    ])->assertCreated();

    $tokens = TableSalle::where('point_de_vente_id', $pdv->id)->pluck('public_token');

    expect($tokens->filter())->toHaveCount(2)
        ->and($tokens->unique())->toHaveCount(2);
});

it('refuse le lot entier si un numéro est déjà pris (422)', function () {
    actingAsTableUser(['table.create']);
    $pdv = PointDeVente::factory()->create();
    TableSalle::factory()->create(['point_de_vente_id' => $pdv->id, 'numero' => 'T02']);

    postJson('/api/tables/lot', [
        'point_de_vente_id' => $pdv->id,
        'lignes' => [
            ['numero' => 'T01', 'capacite' => 2],
            ['numero' => 'T02', 'capacite' => 2],
            ['numero' => 'T03', 'capacite' => 2],
        ],
    ])->assertUnprocessable()->assertJsonValidationErrors('lignes.1.numero');

    // Tout ou rien : ni T01 ni T03 ne doivent exister.
    expect(TableSalle::where('point_de_vente_id', $pdv->id)->count())->toBe(1);
});

/**
 * `unique` interroge l'état d'avant la requête : sans `distinct`, deux lignes
 * identiques passeraient la validation et violeraient l'index à l'insertion.
 */
it('refuse un doublon interne au lot, casse comprise (422)', function () {
    actingAsTableUser(['table.create']);
    $pdv = PointDeVente::factory()->create();

    postJson('/api/tables/lot', [
        'point_de_vente_id' => $pdv->id,
        'lignes' => [
            ['numero' => 'T01', 'capacite' => 2],
            ['numero' => 't01', 'capacite' => 4],
        ],
    ])->assertUnprocessable();

    expect(TableSalle::where('point_de_vente_id', $pdv->id)->count())->toBe(0);
});

it('refuse un numéro déjà porté par une table archivée (422)', function () {
    actingAsTableUser(['table.create']);
    $pdv = PointDeVente::factory()->create();
    TableSalle::factory()->archivee()->create(['point_de_vente_id' => $pdv->id, 'numero' => 'T01']);

    postJson('/api/tables/lot', [
        'point_de_vente_id' => $pdv->id,
        'lignes' => [['numero' => 'T01', 'capacite' => 2]],
    ])->assertUnprocessable()->assertJsonValidationErrors('lignes.0.numero');
});

it('plafonne la taille d\'un lot à 100 tables (422)', function () {
    actingAsTableUser(['table.create']);
    $pdv = PointDeVente::factory()->create();

    $lignes = collect(range(1, 101))
        ->map(fn (int $i) => ['numero' => 'T'.$i, 'capacite' => 2])
        ->all();

    postJson('/api/tables/lot', ['point_de_vente_id' => $pdv->id, 'lignes' => $lignes])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('lignes');
});

it('refuse un lot vide (422)', function () {
    actingAsTableUser(['table.create']);

    postJson('/api/tables/lot', [
        'point_de_vente_id' => PointDeVente::factory()->create()->id,
        'lignes' => [],
    ])->assertUnprocessable()->assertJsonValidationErrors('lignes');
});

it('refuse la création en lot sans la permission table.create', function () {
    actingAsTableUser(['table.view']);

    postJson('/api/tables/lot', [
        'point_de_vente_id' => PointDeVente::factory()->create()->id,
        'lignes' => [['numero' => 'T01', 'capacite' => 2]],
    ])->assertForbidden();
});
