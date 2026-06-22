<?php

declare(strict_types=1);

use App\Models\Caisse;
use App\Models\PointDeVente;
use App\Models\SessionCaisse;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;

use function Pest\Laravel\deleteJson;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

uses(RefreshDatabase::class);

/**
 * @param  string[]  $permissions
 */
function actingAsPdvUser(array $permissions = []): User
{
    $user = User::factory()->create();
    foreach ($permissions as $name) {
        Permission::findOrCreate($name, 'api');
    }
    $user->givePermissionTo($permissions);
    Sanctum::actingAs($user);

    return $user;
}

// ── Création de PDV + caisse auto ─────────────────────────────────────────────

it('crée un point de vente doté d\'une caisse principale', function () {
    $site = Site::factory()->create();
    actingAsPdvUser(['point-de-vente.create']);

    postJson('/api/points-de-vente', [
        'site_id' => $site->id,
        'nom' => 'Restaurant Cocody',
        'type' => 'Restaurant',
    ])
        ->assertCreated()
        ->assertJsonPath('data.nom', 'Restaurant Cocody')
        ->assertJsonPath('data.caisses_count', 1);

    $pdv = PointDeVente::where('nom', 'Restaurant Cocody')->firstOrFail();
    expect($pdv->caisses()->count())->toBe(1)
        ->and($pdv->caisses()->first()->libelle)->toBe('Caisse principale');
});

it('n\'auto-crée pas de caisse pour les PDV de factory', function () {
    $pdv = PointDeVente::factory()->create();

    expect($pdv->caisses()->count())->toBe(0);
});

it('retourne le détail d\'un PDV avec ses caisses', function () {
    $pdv = PointDeVente::factory()->create();
    Caisse::factory()->count(2)->create(['point_de_vente_id' => $pdv->id]);
    actingAsPdvUser(['point-de-vente.view']);

    getJson("/api/points-de-vente/{$pdv->id}")
        ->assertOk()
        ->assertJsonPath('data.caisses_count', 2)
        ->assertJsonCount(2, 'data.caisses')
        ->assertJsonPath('data.caisses.0.sessions_count', 0);
});

// ── Suppression de caisse gardée ──────────────────────────────────────────────

it('supprime une caisse sans historique', function () {
    $caisse = Caisse::factory()->create();
    actingAsPdvUser(['caisse.delete']);

    deleteJson("/api/parametres/caisses/{$caisse->id}")->assertOk();

    expect(Caisse::find($caisse->id))->toBeNull();
});

it('refuse de supprimer une caisse porteuse de sessions (409)', function () {
    $caisse = Caisse::factory()->create();
    SessionCaisse::factory()->create(['caisse_id' => $caisse->id]);
    actingAsPdvUser(['caisse.delete']);

    deleteJson("/api/parametres/caisses/{$caisse->id}")->assertStatus(409);

    expect(Caisse::find($caisse->id))->not->toBeNull();
});
