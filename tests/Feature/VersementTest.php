<?php

declare(strict_types=1);

use App\Models\Caisse;
use App\Models\CommandeClient;
use App\Models\ModeDePaiement;
use App\Models\Paiement;
use App\Models\SessionCaisse;
use App\Models\Site;
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

    // Ces deux fichiers ne testent PAS le périmètre : ils testent le
    // rapprochement et les versements. Leur donner tous les sites est donc la
    // bonne sémantique — et la seule qui évite de réécrire une cinquantaine de
    // corps de test pour une frontière qui n'est pas leur sujet.
    //
    // En deux temps, parce que les factories fabriquent des sites en cascade
    // APRÈS la création de l'utilisateur (caisse → point de vente → site) :
    // les sites déjà là, puis ceux qui viendront.
    $user->sites()->syncWithoutDetaching(Site::pluck('id')->all());
    Site::created(fn (Site $site) => $user->sites()->syncWithoutDetaching([$site->id]));

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

/**
 * L'écran de caisse aligne les sorties de tiroir en face des sessions : c'est
 * la moitié manquante de la lecture d'un écart. Un reversement de pourboires y
 * figure comme les autres, et la seule chose qui dit à qui l'argent est parti
 * est le nom du bénéficiaire — `remis_a` est nul sur cette destination.
 */
it('nomme le bénéficiaire d\'un reversement de pourboires dans la liste', function () {
    actingAsVersementUser(['versement.view']);
    $caisse = Caisse::factory()->create();
    $session = SessionCaisse::factory()->create(['caisse_id' => $caisse->id]);
    $serveur = User::factory()->create(['prenom' => 'Kouassi', 'nom' => 'A.']);
    Versement::factory()->auPersonnel($serveur)->create([
        'caisse_id' => $caisse->id, 'session_caisse_id' => $session->id,
    ]);

    getJson("/api/caisses/{$caisse->id}/versements")
        ->assertOk()
        ->assertJsonPath('data.0.beneficiaire', 'Kouassi A.');
});

/**
 * `en_service` distingue les deux moments, qui ne comptent pas au même endroit.
 * Il n'est rendu que si la session est chargée : sans elle, la ligne qu'on
 * vient de créer s'afficherait sans son moment, là où toutes les autres l'ont.
 */
it('dit le moment du versement qu\'il vient d\'enregistrer', function () {
    actingAsVersementUser(['versement.create']);
    $caisse = Caisse::factory()->create();
    SessionCaisse::factory()->create(['caisse_id' => $caisse->id]);

    postJson("/api/caisses/{$caisse->id}/versements", ['montant' => 5000, 'destination' => 'coffre'])
        ->assertCreated()
        ->assertJsonPath('data.en_service', true);
});

it('marque « après fermeture » un versement fait caisse close', function () {
    actingAsVersementUser(['versement.create']);
    $caisse = Caisse::factory()->create();
    SessionCaisse::factory()->fermee()->create(['caisse_id' => $caisse->id]);

    postJson("/api/caisses/{$caisse->id}/versements", ['montant' => 5000, 'destination' => 'banque'])
        ->assertCreated()
        ->assertJsonPath('data.en_service', false);
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
    $user->sites()->syncWithoutDetaching([$caisse->pointDeVente->site_id]);
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

// ───────────────────────── pourboires reversés ─────────────────────────

/**
 * Deux champs, deux questions. `remis_a` dit qui a EMPORTÉ l'argent — une
 * société de collecte, un convoyeur. `beneficiaire_id` dit de quel SOLDE il se
 * retire, ce qu'un nom écrit à la main ne permettrait pas de calculer.
 */
it('exige un bénéficiaire pour un reversement de pourboires (422)', function () {
    actingAsVersementUser(['versement.create']);
    $caisse = Caisse::factory()->create();
    SessionCaisse::factory()->create(['caisse_id' => $caisse->id]);

    postJson("/api/caisses/{$caisse->id}/versements", [
        'montant' => 5000, 'destination' => 'personnel',
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('beneficiaire_id');
});

it('refuse un bénéficiaire sur une destination qui ne doit rien à personne (422)', function () {
    // Posé par erreur sur un dépôt bancaire, il effacerait la dette d'un serveur.
    $serveur = User::factory()->create();
    actingAsVersementUser(['versement.create']);
    $caisse = Caisse::factory()->create();
    SessionCaisse::factory()->create(['caisse_id' => $caisse->id]);

    postJson("/api/caisses/{$caisse->id}/versements", [
        'montant' => 5000, 'destination' => 'banque', 'beneficiaire_id' => $serveur->id,
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('beneficiaire_id');
});

it('laisse une société de collecte emporter les fonds sans lui devoir quoi que ce soit', function () {
    actingAsVersementUser(['versement.create']);
    $caisse = Caisse::factory()->create();
    SessionCaisse::factory()->create(['caisse_id' => $caisse->id]);

    postJson("/api/caisses/{$caisse->id}/versements", [
        'montant' => 500000,
        'destination' => 'banque',
        'remis_a' => 'Brinks — convoyeur Yao K.',
        'reference' => 'BD-4471',
    ])
        ->assertCreated()
        ->assertJsonPath('data.remis_a', 'Brinks — convoyeur Yao K.')
        ->assertJsonPath('data.beneficiaire_id', null);
});

// ───────────────────────── solde des pourboires ─────────────────────────

/**
 * Un solde et non un instantané : le rapport de fermeture dit ce qu'une session
 * a généré, ce qui ne sert à rien trois jours plus tard si personne n'a
 * reversé. La dette court à travers les sessions.
 */
it('court le solde des pourboires à travers les sessions', function () {
    $ctx = salleContext();
    $serveur = actingAsServeur($ctx->site, ['paiement.create', 'session_caisse.utiliser']);
    $mode = ModeDePaiement::factory()->create(['libelle' => 'Espèces']);

    foreach ([1000, 500] as $pourboire) {
        $commande = commandeServieAvecLigne($ctx, $serveur, 5000, 1);
        postJson("/api/commandes-client/{$commande->id}/paiements", [
            'mode_de_paiement_id' => $mode->id,
            'montant' => 5000,
            'pourboire' => $pourboire,
            'montant_recu' => 5000 + $pourboire,
        ])->assertCreated();
    }

    $gerant = actingAsVersementUser(['versement.view']);
    $gerant->sites()->syncWithoutDetaching([$ctx->site->id]);

    getJson('/api/pourboires/soldes')
        ->assertOk()
        ->assertJsonPath('data.soldes.0.user_id', $serveur->id)
        ->assertJsonPath('data.soldes.0.encaisse', 1500)
        ->assertJsonPath('data.soldes.0.reverse', 0)
        ->assertJsonPath('data.soldes.0.solde', 1500);
});

it('éteint la dette au fil des reversements', function () {
    $ctx = salleContext();
    $serveur = actingAsServeur($ctx->site, ['paiement.create', 'session_caisse.utiliser']);
    $mode = ModeDePaiement::factory()->create(['libelle' => 'Espèces']);
    $commande = commandeServieAvecLigne($ctx, $serveur, 5000, 1);
    postJson("/api/commandes-client/{$commande->id}/paiements", [
        'mode_de_paiement_id' => $mode->id, 'montant' => 5000, 'pourboire' => 1200, 'montant_recu' => 6200,
    ])->assertCreated();

    $gerant = actingAsVersementUser(['versement.view', 'versement.create']);
    $gerant->sites()->syncWithoutDetaching([$ctx->site->id]);

    postJson("/api/caisses/{$ctx->caisse->id}/versements", [
        'montant' => 800, 'destination' => 'personnel', 'beneficiaire_id' => $serveur->id,
    ])->assertCreated();

    getJson('/api/pourboires/soldes')
        ->assertOk()
        ->assertJsonPath('data.soldes.0.encaisse', 1200)
        ->assertJsonPath('data.soldes.0.reverse', 800)
        ->assertJsonPath('data.soldes.0.solde', 400);
});

it('ne compte comme reversement que ce qui va au personnel', function () {
    // Un dépôt au coffre n'éteint la dette de personne.
    $ctx = salleContext();
    $serveur = actingAsServeur($ctx->site, ['paiement.create', 'session_caisse.utiliser']);
    $mode = ModeDePaiement::factory()->create(['libelle' => 'Espèces']);
    $commande = commandeServieAvecLigne($ctx, $serveur, 5000, 1);
    postJson("/api/commandes-client/{$commande->id}/paiements", [
        'mode_de_paiement_id' => $mode->id, 'montant' => 5000, 'pourboire' => 1000, 'montant_recu' => 6000,
    ])->assertCreated();

    $gerant = actingAsVersementUser(['versement.view', 'versement.create']);
    $gerant->sites()->syncWithoutDetaching([$ctx->site->id]);
    postJson("/api/caisses/{$ctx->caisse->id}/versements", [
        'montant' => 1000, 'destination' => 'coffre',
    ])->assertCreated();

    getJson('/api/pourboires/soldes')
        ->assertOk()
        ->assertJsonPath('data.soldes.0.solde', 1000);
});

it('refuse la lecture des soldes sans la permission', function () {
    actingAsVersementUser(['paiement.view']);

    getJson('/api/pourboires/soldes')->assertForbidden();
});

// ───────────────────────── suivi par le serveur ─────────────────────────

/**
 * `pourboire.suivre_les_siens` n'est attribuée à aucun rôle par défaut : c'est
 * à la direction de décider si l'équipe suit ses cumuls. Sans elle, la porte
 * reste fermée sans qu'aucun code ne change.
 */
it('refuse le suivi à un serveur qui n\'a pas la permission', function () {
    $ctx = salleContext();
    actingAsServeur($ctx->site, ['commande_client.view']);

    getJson('/api/pourboires/mon-solde')->assertForbidden();
});

it('montre au serveur son propre solde, et rien d\'autre', function () {
    $ctx = salleContext();
    $serveur = actingAsServeur($ctx->site, [
        'paiement.create', 'session_caisse.utiliser', 'pourboire.suivre_les_siens',
    ]);
    $mode = ModeDePaiement::factory()->create(['libelle' => 'Espèces']);
    $commande = commandeServieAvecLigne($ctx, $serveur, 5000, 1);
    postJson("/api/commandes-client/{$commande->id}/paiements", [
        'mode_de_paiement_id' => $mode->id, 'montant' => 5000, 'pourboire' => 900, 'montant_recu' => 5900,
    ])->assertCreated();

    getJson('/api/pourboires/mon-solde')
        ->assertOk()
        ->assertJsonPath('data.solde.user_id', $serveur->id)
        ->assertJsonPath('data.solde.encaisse', 900)
        ->assertJsonPath('data.solde.solde', 900);
});

/**
 * Ce qu'un serveur veut vraiment vérifier : non pas seulement combien on lui
 * doit, mais quand on l'a payé et de combien.
 */
it('liste au serveur les reversements qu\'il a reçus', function () {
    $ctx = salleContext();
    $serveur = actingAsServeur($ctx->site, [
        'paiement.create', 'session_caisse.utiliser', 'pourboire.suivre_les_siens',
    ]);
    $mode = ModeDePaiement::factory()->create(['libelle' => 'Espèces']);
    $commande = commandeServieAvecLigne($ctx, $serveur, 5000, 1);
    postJson("/api/commandes-client/{$commande->id}/paiements", [
        'mode_de_paiement_id' => $mode->id, 'montant' => 5000, 'pourboire' => 900, 'montant_recu' => 5900,
    ])->assertCreated();

    Versement::factory()->auPersonnel($serveur)->create([
        'caisse_id' => $ctx->caisse->id,
        'session_caisse_id' => $ctx->session->id,
        'montant' => 400,
    ]);

    getJson('/api/pourboires/mon-solde')
        ->assertOk()
        ->assertJsonPath('data.solde.reverse', 400)
        ->assertJsonPath('data.solde.solde', 500)
        ->assertJsonCount(1, 'data.reversements')
        ->assertJsonPath('data.reversements.0.montant', 400);
});

it('ne laisse pas un serveur lire le solde d\'un collègue', function () {
    // Aucun identifiant n'est accepté en paramètre : on ne lit que soi-même.
    $ctx = salleContext();
    $collegue = User::factory()->create();
    $serveur = actingAsServeur($ctx->site, ['pourboire.suivre_les_siens']);

    getJson("/api/pourboires/mon-solde?user_id={$collegue->id}")
        ->assertOk()
        ->assertJsonPath('data.solde.user_id', $serveur->id);
});
