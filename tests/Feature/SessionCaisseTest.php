<?php

declare(strict_types=1);

use App\Models\Caisse;
use App\Models\CommandeClient;
use App\Models\ModeDePaiement;
use App\Models\Paiement;
use App\Models\ParametreEtablissement;
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

/**
 * Le cas qui échouait : `montant_theorique` additionnait TOUS les paiements,
 * alors que `montant_reel` est ce que le caissier a physiquement compté. Un
 * virement gonflait le théorique et creusait un manquant qui n'existait pas —
 * exactement égal au total des règlements hors tiroir.
 */
it('ne compte dans le théorique que les paiements qui passent par le tiroir', function () {
    actingAsSessionUser(['session_caisse.update']);
    $session = SessionCaisse::factory()->create(['fonds_initial' => 50]);
    $commande = CommandeClient::factory()->create(['session_caisse_id' => $session->id]);

    Paiement::factory()->create([
        'commande_client_id' => $commande->id,
        'mode_de_paiement_id' => ModeDePaiement::factory()->create(['libelle' => 'Espèces'])->id,
        'montant' => 200,
    ]);
    Paiement::factory()->create([
        'commande_client_id' => $commande->id,
        'mode_de_paiement_id' => ModeDePaiement::factory()->horsTiroir()->create(['libelle' => 'Virement'])->id,
        'montant' => 300,
    ]);

    // Le caissier compte 250 : le fonds plus les seules espèces. Les 300 du
    // virement sont encaissés, mais pas dans sa main.
    postJson("/api/sessions-caisse/{$session->id}/fermer", ['montant_reel' => 250])
        ->assertOk()
        ->assertJsonPath('data.montant_theorique', '250.00')
        ->assertJsonPath('data.ecart', '0.00');
});

it('expose ce qui a été encaissé hors du tiroir', function () {
    actingAsSessionUser(['session_caisse.update']);
    $session = SessionCaisse::factory()->create(['fonds_initial' => 0]);
    $commande = CommandeClient::factory()->create(['session_caisse_id' => $session->id]);
    Paiement::factory()->create([
        'commande_client_id' => $commande->id,
        'mode_de_paiement_id' => ModeDePaiement::factory()->horsTiroir()->create()->id,
        'montant' => 300,
    ]);

    // Sans ce chiffre, l'écart nul d'une caisse vide reste incompréhensible :
    // on a encaissé 300 et le tiroir est vide, à juste titre.
    postJson("/api/sessions-caisse/{$session->id}/fermer", ['montant_reel' => 0])
        ->assertOk()
        ->assertJsonPath('data.montant_hors_caisse', '300.00');
});

/**
 * Un manquant est de l'argent parti ; un excédent signale plutôt une vente
 * encaissée sans note ouverte. Les afficher du même rouge revenait à ne rien
 * dire au responsable qui ferme.
 */
it('qualifie le sens de l\'écart', function (float $reel, string $attendu) {
    actingAsSessionUser(['session_caisse.update']);
    $session = SessionCaisse::factory()->create(['fonds_initial' => 100]);

    postJson("/api/sessions-caisse/{$session->id}/fermer", ['montant_reel' => $reel])
        ->assertOk()
        ->assertJsonPath('data.ecart_sens', $attendu);
})->with([
    'compte juste' => [100.0, 'juste'],
    'il manque de l\'argent' => [80.0, 'manquant'],
    'il y en a en trop' => [130.0, 'excedent'],
]);

/**
 * Avec de la petite monnaie on ne tombe jamais pile : sans tolérance, cinq
 * francs d'écart alertaient aussi fort que cinquante mille.
 */
it('classe un petit écart comme toléré quand le site en définit une', function () {
    actingAsSessionUser(['session_caisse.update']);
    $session = SessionCaisse::factory()->create(['fonds_initial' => 100]);
    ParametreEtablissement::factory()->create([
        'site_id' => $session->caisse->pointDeVente->site_id,
        'tolerance_ecart_caisse' => 500,
    ]);

    postJson("/api/sessions-caisse/{$session->id}/fermer", ['montant_reel' => 300])
        ->assertOk()
        ->assertJsonPath('data.ecart', '200.00')
        ->assertJsonPath('data.ecart_sens', 'tolere');
});

it('classe la borne exacte de la tolérance comme tolérée', function () {
    actingAsSessionUser(['session_caisse.update']);
    $session = SessionCaisse::factory()->create(['fonds_initial' => 100]);
    ParametreEtablissement::factory()->create([
        'site_id' => $session->caisse->pointDeVente->site_id,
        'tolerance_ecart_caisse' => 500,
    ]);

    // 600 - 100 = 500 pile : la tolérance est inclusive.
    postJson("/api/sessions-caisse/{$session->id}/fermer", ['montant_reel' => 600])
        ->assertOk()
        ->assertJsonPath('data.ecart_sens', 'tolere');
});

it('signale au-delà de la tolérance, dans les deux sens', function () {
    actingAsSessionUser(['session_caisse.update']);
    $session = SessionCaisse::factory()->create(['fonds_initial' => 1000]);
    ParametreEtablissement::factory()->create([
        'site_id' => $session->caisse->pointDeVente->site_id,
        'tolerance_ecart_caisse' => 500,
    ]);

    postJson("/api/sessions-caisse/{$session->id}/fermer", ['montant_reel' => 400])
        ->assertOk()
        ->assertJsonPath('data.ecart_sens', 'manquant');
});

/**
 * Tolérance à 0 = règle désactivée, même convention que
 * `matieres_premieres.seuil_alerte_stock`.
 */
it('sans tolérance configurée, le moindre écart est signalé', function () {
    actingAsSessionUser(['session_caisse.update']);
    $session = SessionCaisse::factory()->create(['fonds_initial' => 100]);
    ParametreEtablissement::factory()->create([
        'site_id' => $session->caisse->pointDeVente->site_id,
        'tolerance_ecart_caisse' => 0,
    ]);

    postJson("/api/sessions-caisse/{$session->id}/fermer", ['montant_reel' => 101])
        ->assertOk()
        ->assertJsonPath('data.ecart_sens', 'excedent');
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
