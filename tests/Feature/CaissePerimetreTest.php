<?php

declare(strict_types=1);

use App\Models\Caisse;
use App\Models\ModeDePaiement;
use App\Models\Permission;
use App\Models\PointDeVente;
use App\Models\SessionCaisse;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

uses(RefreshDatabase::class);

/**
 * Un utilisateur rattaché à des sites PRÉCIS — le contraire de
 * `actingAsSessionUser()`, qui donne tout parce que le périmètre n'est pas son
 * sujet. Ici c'est exactement le sujet.
 *
 * @param  string[]  $permissions
 * @param  int[]  $siteIds
 */
function actingAsCaissierBorne(array $permissions, array $siteIds = []): User
{
    $user = User::factory()->create();

    foreach ($permissions as $name) {
        Permission::findOrCreate($name, 'api');
    }

    $user->givePermissionTo($permissions);
    $user->sites()->sync($siteIds);
    Sanctum::actingAs($user);

    return $user;
}

/** Une caisse posée sur un site neuf, sans lien avec qui que ce soit. */
function caisseAilleurs(): Caisse
{
    return Caisse::factory()->create([
        'point_de_vente_id' => PointDeVente::factory()->create(['site_id' => Site::factory()])->id,
    ]);
}

/*
|--------------------------------------------------------------------------
| Ouvrir, fermer, lire
|--------------------------------------------------------------------------
|
| Une session de caisse est une OPÉRATION : elle appartient au site de son
| tiroir. Le serveur qui l'utilisait était borné depuis le Lot B ; le caissier
| qui l'ouvre, la ferme et la vide ne l'était pas.
|
*/

it('refuse d\'ouvrir une session sur une caisse hors du périmètre', function () {
    $caisse = caisseAilleurs();
    actingAsCaissierBorne(['session_caisse.create'], [Site::factory()->create()->id]);

    postJson('/api/sessions-caisse', ['caisse_id' => $caisse->id, 'fonds_initial' => 50_000])
        ->assertForbidden();
});

it('laisse ouvrir une session sur une caisse de son périmètre', function () {
    $caisse = caisseAilleurs();
    actingAsCaissierBorne(['session_caisse.create'], [$caisse->pointDeVente->site_id]);

    postJson('/api/sessions-caisse', ['caisse_id' => $caisse->id, 'fonds_initial' => 50_000])
        ->assertCreated();
});

/**
 * Le 403 ne doit pas manger le 422 : un `caisse_id` simplement absent est une
 * erreur de saisie, pas un refus d'accès, et l'annoncer comme tel enverrait le
 * caissier chercher une permission qu'il a déjà.
 */
it('répond 422 et non 403 quand la caisse n\'est pas fournie', function () {
    actingAsCaissierBorne(['session_caisse.create'], [Site::factory()->create()->id]);

    postJson('/api/sessions-caisse', ['fonds_initial' => 50_000])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('caisse_id');
});

it('refuse de lire une session hors du périmètre', function () {
    $session = SessionCaisse::factory()->create(['caisse_id' => caisseAilleurs()->id]);
    actingAsCaissierBorne(['session_caisse.view'], [Site::factory()->create()->id]);

    getJson("/api/sessions-caisse/{$session->id}")->assertForbidden();
});

it('refuse de fermer une session hors du périmètre', function () {
    $session = SessionCaisse::factory()->create(['caisse_id' => caisseAilleurs()->id]);
    actingAsCaissierBorne(['session_caisse.update'], [Site::factory()->create()->id]);

    $mode = ModeDePaiement::factory()->create(['libelle' => 'Espèces']);

    postJson("/api/sessions-caisse/{$session->id}/fermer", [
        'rapprochements' => [['mode_de_paiement_id' => $mode->id, 'montant_constate' => 0]],
    ])->assertForbidden();
});

/**
 * La liste rendait TOUTES les sessions de la base — montant théorique, écart et
 * motif de forçage compris — à quiconque portait `session_caisse.view`.
 */
it('ne liste que les sessions du périmètre', function () {
    $mienne = caisseAilleurs();
    SessionCaisse::factory()->create(['caisse_id' => $mienne->id]);
    SessionCaisse::factory()->create(['caisse_id' => caisseAilleurs()->id]);

    actingAsCaissierBorne(['session_caisse.view'], [$mienne->pointDeVente->site_id]);

    $sessions = getJson('/api/sessions-caisse')->assertOk()->json('data.sessions');

    expect($sessions)->toHaveCount(1)
        ->and($sessions[0]['caisse_id'])->toBe($mienne->id);
});

/*
|--------------------------------------------------------------------------
| Sortir l'argent du tiroir
|--------------------------------------------------------------------------
|
| C'est le contrôle qui rend le coffre sûr : un versement `coffre` créera une
| entrée de coffre, et sans lui n'importe quel porteur de `versement.create`
| gonflerait le solde d'un site où il n'a rien à faire. Le comptage suivant y
| sortirait un manquant imputé à quelqu'un.
|
*/

it('refuse de vider un tiroir hors du périmètre', function () {
    $caisse = caisseAilleurs();
    SessionCaisse::factory()->create(['caisse_id' => $caisse->id]);
    actingAsCaissierBorne(['versement.create'], [Site::factory()->create()->id]);

    postJson("/api/caisses/{$caisse->id}/versements", [
        'montant' => 100_000, 'destination' => 'coffre',
    ])->assertForbidden();
});

it('laisse vider un tiroir de son périmètre', function () {
    $caisse = caisseAilleurs();
    SessionCaisse::factory()->create(['caisse_id' => $caisse->id]);
    actingAsCaissierBorne(['versement.create'], [$caisse->pointDeVente->site_id]);

    postJson("/api/caisses/{$caisse->id}/versements", [
        'montant' => 100_000, 'destination' => 'coffre',
    ])->assertCreated();
});

it('refuse de lire les sorties d\'un tiroir hors du périmètre', function () {
    $caisse = caisseAilleurs();
    actingAsCaissierBorne(['versement.view'], [Site::factory()->create()->id]);

    getJson("/api/caisses/{$caisse->id}/versements")->assertForbidden();
});

/**
 * `exists:users,id` laissait éteindre la dette de pourboires d'un serveur de
 * n'importe quel site : son solde tombait sans qu'il ait rien reçu.
 */
it('refuse de reverser un pourboire à quelqu\'un d\'un autre site', function () {
    $caisse = caisseAilleurs();
    SessionCaisse::factory()->create(['caisse_id' => $caisse->id]);
    actingAsCaissierBorne(['versement.create'], [$caisse->pointDeVente->site_id]);

    $etranger = User::factory()->create();
    $etranger->sites()->sync([Site::factory()->create()->id]);

    postJson("/api/caisses/{$caisse->id}/versements", [
        'montant' => 5_000, 'destination' => 'personnel', 'beneficiaire_id' => $etranger->id,
    ])->assertUnprocessable()->assertJsonValidationErrors('beneficiaire_id');
});

it('laisse reverser un pourboire à un serveur du site', function () {
    $caisse = caisseAilleurs();
    SessionCaisse::factory()->create(['caisse_id' => $caisse->id]);
    actingAsCaissierBorne(['versement.create'], [$caisse->pointDeVente->site_id]);

    $serveur = User::factory()->create();
    $serveur->sites()->sync([$caisse->pointDeVente->site_id]);

    postJson("/api/caisses/{$caisse->id}/versements", [
        'montant' => 5_000, 'destination' => 'personnel', 'beneficiaire_id' => $serveur->id,
    ])->assertCreated();
});

/*
|--------------------------------------------------------------------------
| Le super-admin
|--------------------------------------------------------------------------
*/

it('laisse le super-admin passer la frontière, ici comme ailleurs', function () {
    seedRbac();
    $caisse = caisseAilleurs();
    SessionCaisse::factory()->create(['caisse_id' => $caisse->id]);
    actingAsSuperAdmin();

    getJson("/api/caisses/{$caisse->id}/versements")->assertOk();
    getJson('/api/sessions-caisse')->assertOk()->assertJsonCount(1, 'data.sessions');
});
