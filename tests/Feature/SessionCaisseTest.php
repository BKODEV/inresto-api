<?php

declare(strict_types=1);

use App\Models\Caisse;
use App\Models\SessionCaisse;
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
function actingAsSessionUser(array $permissions = []): User
{
    $user = User::factory()->create();
    foreach ($permissions as $name) {
        Permission::findOrCreate($name, 'api');
    }
    $user->givePermissionTo($permissions);
    Sanctum::actingAs($user);

    return $user;
}

// ───────────────────────── ouverture ─────────────────────────

it('ouvre une session de caisse', function () {
    actingAsSessionUser(['session_caisse.create']);
    $caisse = Caisse::factory()->create();

    postJson('/api/sessions-caisse', [
        'caisse_id' => $caisse->id,
        'fonds_initial' => 100,
    ])
        ->assertCreated()
        ->assertJsonPath('data.statut', 'ouverte')
        ->assertJsonPath('data.fonds_initial', '100.00');

    expect(SessionCaisse::where('caisse_id', $caisse->id)->where('statut', 'ouverte')->count())->toBe(1);
});

it('refuse une 2e session ouverte sur la même caisse (409)', function () {
    actingAsSessionUser(['session_caisse.create']);
    $caisse = Caisse::factory()->create();
    SessionCaisse::factory()->create(['caisse_id' => $caisse->id]); // déjà ouverte

    postJson('/api/sessions-caisse', ['caisse_id' => $caisse->id])
        ->assertStatus(409);
});

it('permet d\'ouvrir une session sur une caisse dont la précédente est fermée', function () {
    actingAsSessionUser(['session_caisse.create']);
    $caisse = Caisse::factory()->create();
    SessionCaisse::factory()->fermee()->create(['caisse_id' => $caisse->id]);

    postJson('/api/sessions-caisse', ['caisse_id' => $caisse->id])
        ->assertCreated();
});

// ───────────────────────── fermeture ─────────────────────────

it('ferme une session : statut fermee, ecart calculé, ferme_le renseigné', function () {
    actingAsSessionUser(['session_caisse.update']);
    $session = SessionCaisse::factory()->create(['fonds_initial' => 100]);

    postJson("/api/sessions-caisse/{$session->id}/fermer", ['montant_reel' => 150])
        ->assertOk()
        ->assertJsonPath('data.statut', 'fermee')
        ->assertJsonPath('data.montant_theorique', '100.00') // fonds_initial + paiements (0)
        ->assertJsonPath('data.montant_reel', '150.00')
        ->assertJsonPath('data.ecart', '50.00');

    expect($session->fresh()->ferme_le)->not->toBeNull();
});

it('calcule montant_theorique = fonds_initial (paiements = 0 pour l\'instant)', function () {
    actingAsSessionUser(['session_caisse.update']);
    $session = SessionCaisse::factory()->create(['fonds_initial' => 75]);

    postJson("/api/sessions-caisse/{$session->id}/fermer", ['montant_reel' => 75])
        ->assertOk()
        ->assertJsonPath('data.montant_theorique', '75.00')
        ->assertJsonPath('data.ecart', '0.00');
});

it('accepte un montant_reel = 0 (caisse vide)', function () {
    actingAsSessionUser(['session_caisse.update']);
    $session = SessionCaisse::factory()->create(['fonds_initial' => 0]);

    postJson("/api/sessions-caisse/{$session->id}/fermer", ['montant_reel' => 0])
        ->assertOk()
        ->assertJsonPath('data.statut', 'fermee');
});

it('refuse la fermeture sans montant_reel (422)', function () {
    actingAsSessionUser(['session_caisse.update']);
    $session = SessionCaisse::factory()->create();

    postJson("/api/sessions-caisse/{$session->id}/fermer", [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('montant_reel');
});

it('refuse la fermeture d\'une session déjà fermée (409)', function () {
    actingAsSessionUser(['session_caisse.update']);
    $session = SessionCaisse::factory()->fermee()->create();

    postJson("/api/sessions-caisse/{$session->id}/fermer", ['montant_reel' => 10])
        ->assertStatus(409);
});

// ───────────────────────── lecture ─────────────────────────

it('filtre l\'index des sessions par ?caisse_id', function () {
    actingAsSessionUser(['session_caisse.view']);
    $caisseA = Caisse::factory()->create();
    $caisseB = Caisse::factory()->create();
    SessionCaisse::factory()->fermee()->count(2)->create(['caisse_id' => $caisseA->id]);
    SessionCaisse::factory()->create(['caisse_id' => $caisseB->id]);

    getJson("/api/sessions-caisse?caisse_id={$caisseA->id}")
        ->assertOk()
        ->assertJsonCount(2, 'data.sessions');
});

// ───────────────────────── permissions ─────────────────────────

it('refuse l\'ouverture sans la permission', function () {
    actingAsSessionUser([]);

    postJson('/api/sessions-caisse', ['caisse_id' => Caisse::factory()->create()->id])
        ->assertForbidden();
});
