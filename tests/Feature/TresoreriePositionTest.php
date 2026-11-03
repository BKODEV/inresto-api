<?php

declare(strict_types=1);

use App\Enums\StatutSessionCaisse;
use App\Models\Caisse;
use App\Models\CommandeClient;
use App\Models\ModeDePaiement;
use App\Models\Paiement;
use App\Models\Permission;
use App\Models\PointDeVente;
use App\Models\SessionCaisse;
use App\Models\Site;
use App\Models\TableSalle;
use App\Models\User;
use App\Models\Versement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

use function Pest\Laravel\getJson;

uses(RefreshDatabase::class);

/**
 * @param  string[]  $permissions
 * @param  int[]  $siteIds
 */
function actingAsLecteurDeTresorerie(array $permissions, array $siteIds = []): User
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

/** Une caisse sur son propre site, avec une session ouverte. */
function caisseEnService(float $fondsInitial = 0): SessionCaisse
{
    $caisse = Caisse::factory()->create([
        'point_de_vente_id' => PointDeVente::factory()->create(['site_id' => Site::factory()])->id,
    ]);

    return SessionCaisse::factory()->create([
        'caisse_id' => $caisse->id,
        'fonds_initial' => $fondsInitial,
    ]);
}

/** Un règlement sur une note de la session. */
function reglementSur(SessionCaisse $session, ModeDePaiement $mode, float $montant, float $pourboire = 0): void
{
    $pdv = $session->caisse->point_de_vente_id;

    $commande = CommandeClient::factory()->create([
        'session_caisse_id' => $session->id,
        'table_id' => TableSalle::factory()->create(['point_de_vente_id' => $pdv])->id,
        'statut' => 'servie',
    ]);

    Paiement::factory()->create([
        'commande_client_id' => $commande->id,
        'mode_de_paiement_id' => $mode->id,
        'montant' => $montant,
        'pourboire' => $pourboire,
    ]);
}

/*
|--------------------------------------------------------------------------
| Le calcul
|--------------------------------------------------------------------------
|
| Le chiffre existait depuis toujours — theoriqueParMode() — mais n'était
| calculé qu'à la fermeture. On ne pouvait pas répondre, en plein service, à
| « combien avons-nous en caisse ? ».
|
*/

it('additionne le fonds et l\'encaissé en tiroir, versements déduits', function () {
    $session = caisseEnService(50_000);
    $espece = ModeDePaiement::factory()->create(['libelle' => 'Espèces']);

    reglementSur($session, $espece, 200_000);
    Versement::factory()->create([
        'caisse_id' => $session->caisse_id,
        'session_caisse_id' => $session->id,
        'montant' => 80_000,
        'apres_fermeture' => false,
    ]);

    actingAsLecteurDeTresorerie(
        ['session_caisse.consulter_theorique'],
        [$session->caisse->pointDeVente->site_id],
    );

    $data = getJson("/api/sessions-caisse/{$session->id}/position")->assertOk()->json('data');

    // 50 000 + 200 000 − 80 000
    expect((float) $data['total_en_tiroir'])->toBe(170_000.0)
        ->and((float) $data['versements_en_service'])->toBe(80_000.0)
        ->and((float) $data['fonds_initial'])->toBe(50_000.0);
});

it('sépare ce qui finit dans le tiroir de ce qui n\'y passe pas', function () {
    $session = caisseEnService();
    $espece = ModeDePaiement::factory()->create(['libelle' => 'Espèces']);
    $virement = ModeDePaiement::factory()->horsTiroir()->create(['libelle' => 'Virement']);

    reglementSur($session, $espece, 100_000);
    reglementSur($session, $virement, 300_000);

    actingAsLecteurDeTresorerie(
        ['session_caisse.consulter_theorique'],
        [$session->caisse->pointDeVente->site_id],
    );

    $data = getJson("/api/sessions-caisse/{$session->id}/position")->assertOk()->json('data');

    expect((float) $data['total_en_tiroir'])->toBe(100_000.0)
        ->and((float) $data['total_hors_tiroir'])->toBe(300_000.0);
});

/**
 * Le pourboire est dans le tiroir — il compte donc dans la position — mais il
 * n'appartient pas à l'établissement. Le taire annoncerait plus d'argent
 * disponible qu'il n'y en a.
 */
it('montre à part ce qui, dans le tiroir, est dû aux serveurs', function () {
    $session = caisseEnService();
    $espece = ModeDePaiement::factory()->create(['libelle' => 'Espèces']);

    reglementSur($session, $espece, 100_000, pourboire: 7_000);

    actingAsLecteurDeTresorerie(
        ['session_caisse.consulter_theorique'],
        [$session->caisse->pointDeVente->site_id],
    );

    $data = getJson("/api/sessions-caisse/{$session->id}/position")->assertOk()->json('data');

    expect((float) $data['total_en_tiroir'])->toBe(107_000.0)
        ->and((float) $data['pourboires_dus']['total'])->toBe(7_000.0)
        ->and($data['pourboires_dus']['par_serveur'])->toHaveCount(1);
});

it('ouvre une ligne par contenant actif, même sans mouvement', function () {
    $session = caisseEnService();
    ModeDePaiement::factory()->create(['libelle' => 'Espèces']);
    ModeDePaiement::factory()->horsTiroir()->create(['libelle' => 'Carte bancaire']);

    actingAsLecteurDeTresorerie(
        ['session_caisse.consulter_theorique'],
        [$session->caisse->pointDeVente->site_id],
    );

    $contenants = getJson("/api/sessions-caisse/{$session->id}/position")
        ->assertOk()->json('data.contenants');

    expect($contenants)->toHaveCount(2)
        ->and(collect($contenants)->pluck('mode')->all())->toContain('Espèces', 'Carte bancaire');
});

/**
 * `theoriqueParMode()` rattache le fonds au PREMIER mode en tiroir. Sans aucun
 * mode en tiroir, ce porteur est null et le fonds disparaissait de la
 * ventilation, alors que la fermeture le garde dans `montant_theorique` : le
 * total et la somme des lignes ne tombaient plus d'accord.
 */
it('n\'égare pas le fonds initial quand aucun contenant ne peut le porter', function () {
    $session = caisseEnService(50_000);
    ModeDePaiement::factory()->horsTiroir()->create(['libelle' => 'Virement']);

    actingAsLecteurDeTresorerie(
        ['session_caisse.consulter_theorique'],
        [$session->caisse->pointDeVente->site_id],
    );

    $data = getJson("/api/sessions-caisse/{$session->id}/position")->assertOk()->json('data');

    expect((float) $data['fonds_non_ventile'])->toBe(50_000.0)
        ->and((float) $data['total_en_tiroir'])->toBe(50_000.0);
});

/*
|--------------------------------------------------------------------------
| Qui a le droit de savoir
|--------------------------------------------------------------------------
*/

it('refuse la position à qui n\'a pas la permission dédiée', function () {
    $session = caisseEnService();

    // `session_caisse.view` ne suffit pas : lire une session et lire ce qu'elle
    // devrait contenir sont deux niveaux de confiance différents.
    actingAsLecteurDeTresorerie(['session_caisse.view'], [$session->caisse->pointDeVente->site_id]);

    getJson("/api/sessions-caisse/{$session->id}/position")->assertForbidden();
});

it('refuse la position hors du périmètre de sites', function () {
    $session = caisseEnService();
    actingAsLecteurDeTresorerie(['session_caisse.consulter_theorique'], [Site::factory()->create()->id]);

    getJson("/api/sessions-caisse/{$session->id}/position")->assertForbidden();
});

/**
 * Le cœur de la règle : un montant affiché se recopie. Celui qui comptera ce
 * soir ne doit pas savoir ce qu'il devrait trouver.
 */
it('refuse la position au porteur de la session encore ouverte', function () {
    $session = caisseEnService();

    $porteur = actingAsLecteurDeTresorerie(
        ['session_caisse.consulter_theorique'],
        [$session->caisse->pointDeVente->site_id],
    );
    $session->update(['user_id' => $porteur->id]);

    getJson("/api/sessions-caisse/{$session->id}/position")->assertForbidden();
});

/**
 * Le refus vaut pour l'ACTE, pas pour le rôle : un super-admin qui tient le
 * tiroir compte à l'aveugle comme les autres. Sans quoi la règle serait
 * déclarative — et contournable en se donnant un rôle.
 */
it('refuse la position même au super-admin quand il tient le tiroir', function () {
    seedRbac();
    $session = caisseEnService();
    $admin = actingAsSuperAdmin();
    $session->update(['user_id' => $admin->id]);

    getJson("/api/sessions-caisse/{$session->id}/position")->assertForbidden();
});

/**
 * Une fois le tiroir compté, il n'y a plus rien à souffler : l'écart est figé,
 * et `montant_theorique` est déjà dans la ressource de session.
 */
it('rouvre la lecture au porteur une fois la session fermée', function () {
    $session = caisseEnService();
    ModeDePaiement::factory()->create(['libelle' => 'Espèces']);

    $porteur = actingAsLecteurDeTresorerie(
        ['session_caisse.consulter_theorique'],
        [$session->caisse->pointDeVente->site_id],
    );
    // `statut` et `ferme_le` ne sont pas fillable : seul le service les écrit,
    // et un update() de masse les ignorerait en silence.
    $session->user_id = $porteur->id;
    $session->statut = StatutSessionCaisse::Fermee;
    $session->ferme_le = now();
    $session->save();

    getJson("/api/sessions-caisse/{$session->id}/position")->assertOk();
});

/*
|--------------------------------------------------------------------------
| La position consolidée
|--------------------------------------------------------------------------
|
| « Combien avons-nous, là, maintenant » ne se demandait à personne : le
| théorique n'existait qu'à la fermeture, et rien n'agrégeait les tiroirs.
|
*/

it('additionne les tiroirs de toutes les caisses ouvertes du périmètre', function () {
    $espece = ModeDePaiement::factory()->create(['libelle' => 'Espèces']);

    $premiere = caisseEnService(20_000);
    reglementSur($premiere, $espece, 100_000);

    $seconde = caisseEnService(10_000);
    reglementSur($seconde, $espece, 50_000);

    actingAsLecteurDeTresorerie(['reporting.tresorerie', 'reporting.global']);

    $data = getJson('/api/reporting/tresorerie/position')->assertOk()->json('data');

    expect($data['caisses_ouvertes'])->toHaveCount(2)
        ->and((float) $data['totaux']['en_service'])->toBe(180_000.0)
        ->and((float) $data['totaux']['en_caisse'])->toBe(180_000.0);
});

it('retranche des disponibles ce qui est dû aux serveurs', function () {
    $espece = ModeDePaiement::factory()->create(['libelle' => 'Espèces']);
    $session = caisseEnService();
    reglementSur($session, $espece, 100_000, pourboire: 8_000);

    actingAsLecteurDeTresorerie(['reporting.tresorerie', 'reporting.global']);

    $totaux = getJson('/api/reporting/tresorerie/position')->assertOk()->json('data.totaux');

    expect((float) $totaux['en_caisse'])->toBe(108_000.0)
        ->and((float) $totaux['pourboires_dus'])->toBe(8_000.0)
        // Sur 108 000 dans le tiroir, 8 000 ne nous appartiennent pas.
        ->and((float) $totaux['disponible'])->toBe(100_000.0);
});

it('compte aussi ce qui dort dans les caisses fermées, versements déduits', function () {
    $session = caisseEnService();
    $session->montant_reel = 150_000;
    $session->statut = StatutSessionCaisse::Fermee;
    $session->ferme_le = now();
    $session->save();

    Versement::factory()->create([
        'caisse_id' => $session->caisse_id,
        'session_caisse_id' => $session->id,
        'montant' => 120_000,
        'apres_fermeture' => true,
    ]);

    actingAsLecteurDeTresorerie(['reporting.tresorerie', 'reporting.global']);

    $data = getJson('/api/reporting/tresorerie/position')->assertOk()->json('data');

    // 150 000 comptés, 120 000 partis au coffre : il reste 30 000 dans le tiroir.
    expect($data['caisses_au_repos'])->toHaveCount(1)
        ->and((float) $data['caisses_au_repos'][0]['reste_en_tiroir'])->toBe(30_000.0)
        ->and((float) $data['totaux']['au_repos'])->toBe(30_000.0);
});

/**
 * `montant_reel` est nullable par conception : null = personne n'a compté, ce
 * qui n'est pas la même chose que zéro. Le traiter comme zéro annoncerait une
 * caisse vide là où personne n'a regardé.
 */
it('isole les caisses fermées sans comptage au lieu de les compter pour zéro', function () {
    $session = caisseEnService();
    $session->statut = StatutSessionCaisse::Fermee;
    $session->ferme_le = now();
    $session->save();

    actingAsLecteurDeTresorerie(['reporting.tresorerie', 'reporting.global']);

    $data = getJson('/api/reporting/tresorerie/position')->assertOk()->json('data');

    expect($data['caisses_au_repos'])->toHaveCount(0)
        ->and($data['non_comptees'])->toHaveCount(1)
        ->and((float) $data['totaux']['au_repos'])->toBe(0.0);
});

it('oublie le reliquat d\'une caisse qui a rouvert', function () {
    $session = caisseEnService();
    $session->montant_reel = 150_000;
    $session->statut = StatutSessionCaisse::Fermee;
    $session->ferme_le = now();
    $session->save();

    // L'argent est reparti dans la session en cours : le compter deux fois
    // doublerait la trésorerie de l'établissement.
    SessionCaisse::factory()->create(['caisse_id' => $session->caisse_id, 'fonds_initial' => 150_000]);

    actingAsLecteurDeTresorerie(['reporting.tresorerie', 'reporting.global']);

    $data = getJson('/api/reporting/tresorerie/position')->assertOk()->json('data');

    expect($data['caisses_au_repos'])->toHaveCount(0)
        ->and((float) $data['totaux']['en_caisse'])->toBe(150_000.0);
});

it('borne la position consolidée au périmètre de sites', function () {
    $espece = ModeDePaiement::factory()->create(['libelle' => 'Espèces']);
    $mienne = caisseEnService();
    reglementSur($mienne, $espece, 100_000);

    $ailleurs = caisseEnService();
    reglementSur($ailleurs, $espece, 900_000);

    actingAsLecteurDeTresorerie(['reporting.tresorerie'], [$mienne->caisse->pointDeVente->site_id]);

    $data = getJson('/api/reporting/tresorerie/position')->assertOk()->json('data');

    expect($data['caisses_ouvertes'])->toHaveCount(1)
        ->and((float) $data['totaux']['en_caisse'])->toBe(100_000.0);
});

/**
 * Sans `?site_id` explicite, un utilisateur hors périmètre obtient 200 et une
 * liste vide — `SitePerimetre::resoudre()` ne lève que sur un site DEMANDÉ.
 */
it('refuse un site explicitement demandé hors du périmètre', function () {
    $ailleurs = caisseEnService();
    $mien = Site::factory()->create();

    actingAsLecteurDeTresorerie(['reporting.tresorerie'], [$mien->id]);

    $siteInterdit = $ailleurs->caisse->pointDeVente->site_id;

    getJson("/api/reporting/tresorerie/position?site_id={$siteInterdit}")->assertForbidden();
});

it('refuse la trésorerie à qui porte les autres familles de reporting', function () {
    caisseEnService();
    actingAsLecteurDeTresorerie(['reporting.ventes', 'reporting.dashboard', 'reporting.global']);

    getJson('/api/reporting/tresorerie/position')->assertForbidden();
});
