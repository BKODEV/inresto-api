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
