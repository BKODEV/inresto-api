<?php

declare(strict_types=1);

use App\Models\Caisse;
use App\Models\CommandeClient;
use App\Models\ModeDePaiement;
use App\Models\Paiement;
use App\Models\SessionCaisse;
use App\Models\User;
use App\Models\Versement;
use App\Services\PdfService;
use App\Services\SessionCaisseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;

use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

uses(RefreshDatabase::class);

/**
 * @param  string[]  $permissions
 */
function actingAsVersementUser(array $permissions = []): User
{
    $user = User::factory()->create();
    foreach ($permissions as $name) {
        Permission::findOrCreate($name, 'api');
    }
    $user->givePermissionTo($permissions);
    Sanctum::actingAs($user);

    return $user;
}

/** Constat de fermeture pour tous les modes actifs, celui nommé au montant voulu. */
function compteTout(float $montant, ModeDePaiement $mode): array
{
    return ['rapprochements' => ModeDePaiement::query()
        ->where('statut', 'actif')
        ->get()
        ->map(fn (ModeDePaiement $actif): array => [
            'mode_de_paiement_id' => $actif->id,
            'montant_constate' => $actif->id === $mode->id ? $montant : 0,
        ])
        ->all()];
}

// ───────────────────────── enregistrement ─────────────────────────

it('enregistre un versement et le rattache à la session ouverte', function () {
    $user = actingAsVersementUser(['versement.create']);
    $caisse = Caisse::factory()->create();
    $session = SessionCaisse::factory()->create(['caisse_id' => $caisse->id]);

    postJson("/api/caisses/{$caisse->id}/versements", [
        'montant' => 100000,
        'destination' => 'coffre',
        'remis_a' => 'Aminata D.',
    ])
        ->assertCreated()
        ->assertJsonPath('data.montant', '100000.00')
        ->assertJsonPath('data.destination', 'coffre')
        ->assertJsonPath('data.session_caisse_id', $session->id);

    expect(Versement::first()->user_id)->toBe($user->id);
});

it('rattache à la dernière session fermée quand la caisse dort', function () {
    // Entre deux services : l'argent sort quand même, et il devra expliquer
    // l'écart d'ouverture de la session suivante.
    actingAsVersementUser(['versement.create']);
    $caisse = Caisse::factory()->create();
    $fermee = SessionCaisse::factory()->fermee()->create(['caisse_id' => $caisse->id]);

    postJson("/api/caisses/{$caisse->id}/versements", ['montant' => 5000, 'destination' => 'banque'])
        ->assertCreated()
        ->assertJsonPath('data.session_caisse_id', $fermee->id);
});

it('refuse un versement sur une caisse jamais ouverte (409)', function () {
    actingAsVersementUser(['versement.create']);
    $caisse = Caisse::factory()->create();

    postJson("/api/caisses/{$caisse->id}/versements", ['montant' => 5000, 'destination' => 'coffre'])
        ->assertStatus(409);
});

it('refuse une destination hors de la liste (422)', function () {
    actingAsVersementUser(['versement.create']);
    $caisse = Caisse::factory()->create();
    SessionCaisse::factory()->create(['caisse_id' => $caisse->id]);

    postJson("/api/caisses/{$caisse->id}/versements", ['montant' => 5000, 'destination' => 'poche'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('destination');
});

/**
 * Sortir de l'argent du tiroir n'est pas encaisser : sans permission dédiée,
 * tout caissier pourrait vider la caisse du seul fait qu'il sait y encaisser.
 */
it('refuse le versement sans la permission dédiée', function () {
    actingAsVersementUser(['paiement.create', 'session_caisse.create']);
    $caisse = Caisse::factory()->create();
    SessionCaisse::factory()->create(['caisse_id' => $caisse->id]);

    postJson("/api/caisses/{$caisse->id}/versements", ['montant' => 5000, 'destination' => 'coffre'])
        ->assertForbidden();
});

it('liste les versements d\'une caisse, du plus récent au plus ancien', function () {
    actingAsVersementUser(['versement.view']);
    $caisse = Caisse::factory()->create();
    $session = SessionCaisse::factory()->create(['caisse_id' => $caisse->id]);
    Versement::factory()->create([
        'caisse_id' => $caisse->id, 'session_caisse_id' => $session->id,
        'montant' => 1000, 'date_heure' => now()->subHour(),
    ]);
    Versement::factory()->create([
        'caisse_id' => $caisse->id, 'session_caisse_id' => $session->id,
        'montant' => 2000, 'date_heure' => now(),
    ]);

    getJson("/api/caisses/{$caisse->id}/versements")
        ->assertOk()
        ->assertJsonPath('data.0.montant', '2000.00')
        ->assertJsonPath('data.1.montant', '1000.00');
});

// ───────────────────────── effet sur le théorique ─────────────────────────

/**
 * Le théorique valait `fonds + encaissé`, sans terme de sortie. Porter une
 * partie de la recette au coffre en plein service creusait donc un manquant
 * fantôme exactement égal à la somme sortie.
 */
it('retranche du théorique un versement fait pendant le service', function () {
    $user = actingAsVersementUser(['versement.create', 'session_caisse.update']);
    $caisse = Caisse::factory()->create();
    $session = SessionCaisse::factory()->create(['caisse_id' => $caisse->id, 'fonds_initial' => 10000]);
    $especes = ModeDePaiement::factory()->create(['libelle' => 'Espèces']);
    $commande = CommandeClient::factory()->create(['session_caisse_id' => $session->id]);
    Paiement::factory()->create([
        'commande_client_id' => $commande->id,
        'mode_de_paiement_id' => $especes->id,
        'montant' => 174000,
    ]);

    postJson("/api/caisses/{$caisse->id}/versements", [
        'montant' => 100000, 'destination' => 'coffre',
    ])->assertCreated();

    // 10 000 + 174 000 − 100 000 = 84 000 doivent rester dans le tiroir.
    postJson("/api/sessions-caisse/{$session->id}/fermer", compteTout(84000, $especes))
        ->assertOk()
        ->assertJsonPath('data.montant_theorique', '84000.00')
        ->assertJsonPath('data.ecart', '0.00')
        ->assertJsonPath('data.ecart_sens', 'juste');
});

// ───────────────────────── écart de transition ─────────────────────────

/**
 * Chaque session bouclait déjà, mais la chaîne restait ouverte : on fermait à
 * 18 500 et on rouvrait à 10 000 sans que personne dise où étaient passés les
 * 8 500.
 */
it('signale un écart quand la nouvelle session ne reprend pas le compte laissé', function () {
    $user = actingAsVersementUser(['session_caisse.create', 'session_caisse.update']);
    $caisse = Caisse::factory()->create();
    $especes = ModeDePaiement::factory()->create(['libelle' => 'Espèces']);
    $ancienne = SessionCaisse::factory()->create(['caisse_id' => $caisse->id, 'fonds_initial' => 18500]);

    app(SessionCaisseService::class)->fermer($ancienne, compteTout(18500, $especes), $user->id);

    postJson('/api/sessions-caisse', ['caisse_id' => $caisse->id, 'fonds_initial' => 10000])
        ->assertCreated()
        ->assertJsonPath('data.ecart_ouverture', '-8500.00');
});

it('ne signale rien sur un passage de main à main entre caissiers', function () {
    // Le service continue, le tiroir change de mains sans que rien n'en sorte.
    $user = actingAsVersementUser(['session_caisse.create', 'session_caisse.update']);
    $caisse = Caisse::factory()->create();
    $especes = ModeDePaiement::factory()->create(['libelle' => 'Espèces']);
    $ancienne = SessionCaisse::factory()->create(['caisse_id' => $caisse->id, 'fonds_initial' => 18500]);

    app(SessionCaisseService::class)->fermer($ancienne, compteTout(18500, $especes), $user->id);

    postJson('/api/sessions-caisse', ['caisse_id' => $caisse->id, 'fonds_initial' => 18500])
        ->assertCreated()
        ->assertJsonPath('data.ecart_ouverture', '0.00');
});

it('tient le versement pour l\'explication de la différence', function () {
    $user = actingAsVersementUser(['session_caisse.create', 'session_caisse.update', 'versement.create']);
    $caisse = Caisse::factory()->create();
    $especes = ModeDePaiement::factory()->create(['libelle' => 'Espèces']);
    $ancienne = SessionCaisse::factory()->create(['caisse_id' => $caisse->id, 'fonds_initial' => 18500]);

    app(SessionCaisseService::class)->fermer($ancienne, compteTout(18500, $especes), $user->id);

    // 8 500 partent au coffre APRÈS la fermeture : la suivante doit ouvrir à
    // 10 000 sans que rien ne soit signalé.
    postJson("/api/caisses/{$caisse->id}/versements", [
        'montant' => 8500, 'destination' => 'coffre',
    ])->assertCreated();

    postJson('/api/sessions-caisse', ['caisse_id' => $caisse->id, 'fonds_initial' => 10000])
        ->assertCreated()
        ->assertJsonPath('data.ecart_ouverture', '0.00');
});

it('n\'a rien à comparer sur la première session d\'une caisse', function () {
    actingAsVersementUser(['session_caisse.create']);
    $caisse = Caisse::factory()->create();

    postJson('/api/sessions-caisse', ['caisse_id' => $caisse->id, 'fonds_initial' => 10000])
        ->assertCreated()
        ->assertJsonPath('data.ecart_ouverture', null);
});

it('n\'a rien à comparer si la session précédente n\'a pas été comptée', function () {
    // Sessions closes avant l'obligation de rapprocher : leur tiroir n'a jamais
    // été compté, on n'invente pas une transition à partir de rien.
    actingAsVersementUser(['session_caisse.create']);
    $caisse = Caisse::factory()->create();
    SessionCaisse::factory()->fermee()->create(['caisse_id' => $caisse->id, 'montant_reel' => null]);

    postJson('/api/sessions-caisse', ['caisse_id' => $caisse->id, 'fonds_initial' => 10000])
        ->assertCreated()
        ->assertJsonPath('data.ecart_ouverture', null);
});

// ───────────────────────── traces ─────────────────────────

it('porte les versements et la transition sur le rapport de fermeture', function () {
    $user = actingAsVersementUser([
        'versement.create', 'session_caisse.create', 'session_caisse.update', 'session_caisse.view',
    ]);
    $caisse = Caisse::factory()->create();
    $especes = ModeDePaiement::factory()->create(['libelle' => 'Espèces']);
    $session = SessionCaisse::factory()->create(['caisse_id' => $caisse->id, 'fonds_initial' => 110000]);

    postJson("/api/caisses/{$caisse->id}/versements", [
        'montant' => 100000, 'destination' => 'coffre', 'remis_a' => 'Aminata D.',
    ])->assertCreated();

    app(SessionCaisseService::class)->fermer($session, compteTout(10000, $especes), $user->id);

    $html = app(PdfService::class)->htmlRapportFermeture($session->fresh());

    expect($html)
        ->toContain('VERSEMENTS')
        ->toContain('Coffre')
        ->toContain('remis à Aminata D.')
        ->toContain('100 000');
});

it('inscrit le versement au journal d\'activité', function () {
    $caisse = Caisse::factory()->create();
    // Le journal est borné au périmètre de sites de l'utilisateur.
    $user = actingAsVersementUser(['versement.create', 'journal.view']);
    $user->sites()->attach($caisse->pointDeVente->site_id);
    SessionCaisse::factory()->create(['caisse_id' => $caisse->id]);

    postJson("/api/caisses/{$caisse->id}/versements", [
        'montant' => 50000, 'destination' => 'banque', 'reference' => 'BD-0042',
    ])->assertCreated();

    getJson('/api/journal?type[]=versement')
        ->assertOk()
        ->assertJsonPath('data.evenements.0.type', 'versement')
        ->assertJsonPath('data.evenements.0.libelle', 'Versement — Banque')
        ->assertJsonPath('data.evenements.0.montant', 50000);
});
