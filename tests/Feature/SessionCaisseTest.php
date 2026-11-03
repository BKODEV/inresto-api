<?php

declare(strict_types=1);

use App\Models\Caisse;
use App\Models\CommandeClient;
use App\Models\ModeDePaiement;
use App\Models\Paiement;
use App\Models\ParametreEtablissement;
use App\Models\SessionCaisse;
use App\Models\Site;
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

/**
 * Le constaté de fermeture s'exprime désormais par CONTENANT : un montant par
 * mode de paiement. Ce helper pose le cas courant — le seul tiroir, compté.
 *
 * @return array<string, mixed>
 */
function tiroirCompte(float $montant, ?ModeDePaiement $mode = null): array
{
    $mode ??= ModeDePaiement::query()->where('statut', 'actif')->orderBy('ordre')->orderBy('id')->first()
        ?? ModeDePaiement::factory()->create(['libelle' => 'Espèces']);

    // La fermeture exige un compte par contenant : les autres modes actifs sont
    // déclarés à zéro, ce qui est un compte valide et non une absence.
    return ['rapprochements' => ModeDePaiement::query()
        ->where('statut', 'actif')
        ->get()
        ->map(fn (ModeDePaiement $actif): array => [
            'mode_de_paiement_id' => $actif->id,
            'montant_constate' => $actif->id === $mode->id ? $montant : 0,
        ])
        ->all()];
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

    postJson("/api/sessions-caisse/{$session->id}/fermer", tiroirCompte(150))
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

    postJson("/api/sessions-caisse/{$session->id}/fermer", tiroirCompte(75))
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
    postJson("/api/sessions-caisse/{$session->id}/fermer", tiroirCompte(250))
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
    postJson("/api/sessions-caisse/{$session->id}/fermer", tiroirCompte(0))
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

    postJson("/api/sessions-caisse/{$session->id}/fermer", tiroirCompte($reel))
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

    postJson("/api/sessions-caisse/{$session->id}/fermer", tiroirCompte(300))
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
    postJson("/api/sessions-caisse/{$session->id}/fermer", tiroirCompte(600))
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

    postJson("/api/sessions-caisse/{$session->id}/fermer", tiroirCompte(400))
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

    postJson("/api/sessions-caisse/{$session->id}/fermer", tiroirCompte(101))
        ->assertOk()
        ->assertJsonPath('data.ecart_sens', 'excedent');
});

// ───────────────────────── rapprochement par contenant ─────────────────────────

/**
 * Un mode de paiement désigne un contenant : le tiroir, le terminal, la
 * pochette. Chacun se rapproche du sien, et l'écart de caisse ne porte plus que
 * sur le tiroir.
 */
it('rapproche chaque contenant séparément', function () {
    actingAsSessionUser(['session_caisse.update']);
    $session = SessionCaisse::factory()->create(['fonds_initial' => 10000]);
    $commande = CommandeClient::factory()->create(['session_caisse_id' => $session->id]);

    $especes = ModeDePaiement::factory()->create(['libelle' => 'Espèces', 'ordre' => 1]);
    $carte = ModeDePaiement::factory()->horsTiroir()->create(['libelle' => 'Carte', 'ordre' => 2]);

    Paiement::factory()->create([
        'commande_client_id' => $commande->id, 'mode_de_paiement_id' => $especes->id, 'montant' => 174000,
    ]);
    Paiement::factory()->create([
        'commande_client_id' => $commande->id, 'mode_de_paiement_id' => $carte->id, 'montant' => 80000,
    ]);

    // Le tiroir est court de 500 ; le terminal tombe pile.
    postJson("/api/sessions-caisse/{$session->id}/fermer", ['rapprochements' => [
        ['mode_de_paiement_id' => $especes->id, 'montant_constate' => 183500],
        ['mode_de_paiement_id' => $carte->id, 'montant_constate' => 80000],
    ]])
        ->assertOk()
        // L'écart de caisse ne porte que sur le tiroir : fonds + espèces.
        ->assertJsonPath('data.montant_theorique', '184000.00')
        ->assertJsonPath('data.montant_reel', '183500.00')
        ->assertJsonPath('data.ecart', '-500.00')
        ->assertJsonPath('data.rapprochements.0.mode', 'Espèces')
        ->assertJsonPath('data.rapprochements.0.ecart', '-500.00')
        ->assertJsonPath('data.rapprochements.1.mode', 'Carte')
        ->assertJsonPath('data.rapprochements.1.ecart', '0.00');
});

/**
 * L'erreur que rien ne voyait : une transaction tapée sur le terminal sans note
 * ouverte. Elle ne touche pas le tiroir, donc l'écart de caisse reste nul.
 */
it('révèle une transaction du terminal absente du système', function () {
    actingAsSessionUser(['session_caisse.update']);
    $session = SessionCaisse::factory()->create(['fonds_initial' => 0]);
    $carte = ModeDePaiement::factory()->horsTiroir()->create(['libelle' => 'Carte']);

    // Le système n'a rien enregistré en carte ; le terminal affiche 5 000.
    postJson("/api/sessions-caisse/{$session->id}/fermer", ['rapprochements' => [
        ['mode_de_paiement_id' => $carte->id, 'montant_constate' => 5000],
    ]])
        ->assertOk()
        ->assertJsonPath('data.rapprochements.0.montant_theorique', '0.00')
        ->assertJsonPath('data.rapprochements.0.ecart', '5000.00')
        ->assertJsonPath('data.rapprochements.0.ecart_sens', 'excedent');
});

it('ouvre une ligne pour chaque mode actif, même sans mouvement', function () {
    // Sans ligne, une carte encaissée hors système n'aurait nulle part où
    // apparaître : c'est précisément l'erreur qu'on cherche.
    actingAsSessionUser(['session_caisse.update']);
    $session = SessionCaisse::factory()->create(['fonds_initial' => 0]);
    $especes = ModeDePaiement::factory()->create(['libelle' => 'Espèces']);
    $carte = ModeDePaiement::factory()->horsTiroir()->create(['libelle' => 'Carte']);
    ModeDePaiement::factory()->inactif()->create(['libelle' => 'Retiré']);

    postJson("/api/sessions-caisse/{$session->id}/fermer", ['rapprochements' => [
        ['mode_de_paiement_id' => $especes->id, 'montant_constate' => 0],
        ['mode_de_paiement_id' => $carte->id, 'montant_constate' => 0],
    ]])
        ->assertOk()
        ->assertJsonCount(2, 'data.rapprochements');
});

/**
 * La tolérance existe parce qu'avec de la petite monnaie on ne tombe jamais
 * pile. Un terminal, lui, tombe toujours pile : un écart y est une transaction
 * manquante, pas du bruit.
 */
it('ne tolère un écart que dans le tiroir', function () {
    actingAsSessionUser(['session_caisse.update']);
    $session = SessionCaisse::factory()->create(['fonds_initial' => 1000]);
    ParametreEtablissement::factory()->create([
        'site_id' => $session->caisse->pointDeVente->site_id,
        'tolerance_ecart_caisse' => 500,
    ]);
    $especes = ModeDePaiement::factory()->create(['libelle' => 'Espèces', 'ordre' => 1]);
    $carte = ModeDePaiement::factory()->horsTiroir()->create(['libelle' => 'Carte', 'ordre' => 2]);

    // Même écart de 300 des deux côtés, deux verdicts différents.
    postJson("/api/sessions-caisse/{$session->id}/fermer", ['rapprochements' => [
        ['mode_de_paiement_id' => $especes->id, 'montant_constate' => 700],
        ['mode_de_paiement_id' => $carte->id, 'montant_constate' => 300],
    ]])
        ->assertOk()
        ->assertJsonPath('data.rapprochements.0.ecart_sens', 'tolere')
        ->assertJsonPath('data.rapprochements.1.ecart_sens', 'excedent');
});

/**
 * Le fonds initial est de l'argent liquide : il revient au premier mode en
 * tiroir, sans quoi le caissier devrait le séparer des espèces qu'il compte.
 */
it('rattache le fonds initial au premier contenant en tiroir', function () {
    actingAsSessionUser(['session_caisse.update']);
    $session = SessionCaisse::factory()->create(['fonds_initial' => 10000]);
    $especes = ModeDePaiement::factory()->create(['libelle' => 'Espèces', 'ordre' => 1]);
    $carte = ModeDePaiement::factory()->horsTiroir()->create(['libelle' => 'Carte', 'ordre' => 2]);

    postJson("/api/sessions-caisse/{$session->id}/fermer", ['rapprochements' => [
        ['mode_de_paiement_id' => $especes->id, 'montant_constate' => 10000],
        ['mode_de_paiement_id' => $carte->id, 'montant_constate' => 0],
    ]])
        ->assertOk()
        ->assertJsonPath('data.rapprochements.0.montant_theorique', '10000.00')
        ->assertJsonPath('data.rapprochements.0.ecart_sens', 'juste')
        // Le terminal, lui, ne porte pas le fonds.
        ->assertJsonPath('data.rapprochements.1.montant_theorique', '0.00');
});

// ───────────────────────── fermeture forcée ─────────────────────────

/**
 * La règle du compte par contenant est la bonne, mais une règle sans issue
 * immobilise : un terminal qui refuse d'imprimer son ticket, et la caisse ne se
 * ferme plus, donc le service suivant ne démarre pas. Le forçage est la porte
 * de sortie, et elle est étroite — permission dédiée, motif écrit.
 */
it('laisse un responsable fermer sans tout compter, avec un motif', function () {
    actingAsSessionUser(['session_caisse.update', 'session_caisse.forcer_fermeture']);
    $session = SessionCaisse::factory()->create(['fonds_initial' => 0]);
    $especes = ModeDePaiement::factory()->create(['libelle' => 'Espèces']);
    ModeDePaiement::factory()->horsTiroir()->create(['libelle' => 'Carte']);

    postJson("/api/sessions-caisse/{$session->id}/fermer", [
        'forcer' => true,
        'motif_forcage' => 'Le terminal ne sort pas son ticket.',
        'rapprochements' => [
            ['mode_de_paiement_id' => $especes->id, 'montant_constate' => 0],
        ],
    ])
        ->assertOk()
        ->assertJsonPath('data.statut', 'fermee')
        ->assertJsonPath('data.motif_forcage', 'Le terminal ne sort pas son ticket.')
        // Le contenant non compté reste non rapproché : on n'invente pas d'écart.
        ->assertJsonPath('data.rapprochements.1.montant_constate', null)
        ->assertJsonPath('data.rapprochements.1.ecart_sens', 'non_rapproche');
});

it('refuse le forçage à qui n\'en a pas la permission (422)', function () {
    // Savoir fermer une caisse ne doit pas suffire à contourner le contrôle.
    actingAsSessionUser(['session_caisse.update']);
    $session = SessionCaisse::factory()->create(['fonds_initial' => 0]);
    ModeDePaiement::factory()->create(['libelle' => 'Espèces']);

    postJson("/api/sessions-caisse/{$session->id}/fermer", [
        'forcer' => true,
        'motif_forcage' => 'Parce que.',
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('forcer');

    expect($session->fresh()->estOuverte())->toBeTrue();
});

it('exige un motif pour forcer (422)', function () {
    // Un forçage sans explication ne vaut pas mieux qu'un trou.
    actingAsSessionUser(['session_caisse.update', 'session_caisse.forcer_fermeture']);
    $session = SessionCaisse::factory()->create(['fonds_initial' => 0]);
    ModeDePaiement::factory()->create(['libelle' => 'Espèces']);

    postJson("/api/sessions-caisse/{$session->id}/fermer", ['forcer' => true])
        ->assertStatus(422)
        ->assertJsonValidationErrors('motif_forcage');
});

/**
 * Le forçage passe outre le COMPTE, jamais l'impayé : une note servie non
 * réglée se solde ou s'annule, elle ne se contourne pas.
 */
it('ne contourne pas une note servie non réglée (409)', function () {
    actingAsSessionUser(['session_caisse.update', 'session_caisse.forcer_fermeture']);
    $session = SessionCaisse::factory()->create(['fonds_initial' => 0]);
    CommandeClient::factory()->servie()->create(['session_caisse_id' => $session->id]);

    postJson("/api/sessions-caisse/{$session->id}/fermer", [
        'forcer' => true,
        'motif_forcage' => 'Le terminal est en panne.',
    ])->assertStatus(409);
});

it('ne marque pas forcée une fermeture ordinaire', function () {
    actingAsSessionUser(['session_caisse.update', 'session_caisse.forcer_fermeture']);
    $session = SessionCaisse::factory()->create(['fonds_initial' => 0]);
    ModeDePaiement::factory()->create(['libelle' => 'Espèces']);

    postJson("/api/sessions-caisse/{$session->id}/fermer", tiroirCompte(0))
        ->assertOk()
        ->assertJsonPath('data.motif_forcage', null);
});

// ───────────────────────── notes en suspens ─────────────────────────

/**
 * Une note servie non réglée est un impayé. Fermer par-dessus la rendrait
 * orpheline : son argent ne compterait dans le théorique d'aucune caisse, et
 * l'impayé disparaîtrait sans laisser de trace exploitable.
 */
it('refuse de fermer sur une note servie non réglée (409)', function () {
    actingAsSessionUser(['session_caisse.update']);
    $session = SessionCaisse::factory()->create(['fonds_initial' => 100]);
    CommandeClient::factory()->servie()->create(['session_caisse_id' => $session->id]);

    postJson("/api/sessions-caisse/{$session->id}/fermer", tiroirCompte(100))
        ->assertStatus(409)
        ->assertJsonPath('message', fn (string $m) => str_contains($m, 'non réglée'));

    expect($session->fresh()->estOuverte())->toBeTrue();
});

it('laisse fermer quand la note servie a été soldée', function () {
    actingAsSessionUser(['session_caisse.update']);
    $session = SessionCaisse::factory()->create(['fonds_initial' => 100]);
    CommandeClient::factory()->create([
        'session_caisse_id' => $session->id,
        'statut' => 'encaissee',
    ]);

    postJson("/api/sessions-caisse/{$session->id}/fermer", tiroirCompte(100))
        ->assertOk()
        ->assertJsonPath('data.statut', 'fermee');
});

/**
 * Une table qui mange encore à l'heure de la relève est normale : elle ne doit
 * pas retenir la caisse.
 */
it('laisse fermer malgré une note encore en cuisine', function () {
    actingAsSessionUser(['session_caisse.update']);
    $session = SessionCaisse::factory()->create(['fonds_initial' => 100]);
    CommandeClient::factory()->envoyeeEnCuisine()->create(['session_caisse_id' => $session->id]);

    postJson("/api/sessions-caisse/{$session->id}/fermer", tiroirCompte(100))
        ->assertOk()
        ->assertJsonPath('data.statut', 'fermee');
});

/**
 * Le report a lieu à l'ouverture, faute de session d'accueil à la fermeture.
 * Aucun argent ne suit : un paiement n'est accepté que sur une note servie,
 * donc une note en cours n'en porte aucun.
 */
it('reporte sur la nouvelle session les notes laissées en cours de service', function () {
    actingAsSessionUser(['session_caisse.create', 'session_caisse.update']);
    $caisse = Caisse::factory()->create();
    $ancienne = SessionCaisse::factory()->create(['caisse_id' => $caisse->id]);

    $enCuisine = CommandeClient::factory()->envoyeeEnCuisine()->create(['session_caisse_id' => $ancienne->id]);
    $encaissee = CommandeClient::factory()->create([
        'session_caisse_id' => $ancienne->id,
        'statut' => 'encaissee',
    ]);

    postJson("/api/sessions-caisse/{$ancienne->id}/fermer", tiroirCompte(0))->assertOk();
    $nouvelle = postJson('/api/sessions-caisse', ['caisse_id' => $caisse->id])->assertCreated();

    $nouvelleId = $nouvelle->json('data.id');
    expect($enCuisine->fresh()->session_caisse_id)->toBe($nouvelleId)
        // Une note close reste sur la session qui l'a encaissée.
        ->and($encaissee->fresh()->session_caisse_id)->toBe($ancienne->id);
});

it('n\'emporte pas les notes en cours d\'une autre caisse', function () {
    actingAsSessionUser(['session_caisse.create']);
    $caisse = Caisse::factory()->create();
    $autreCaisse = Caisse::factory()->create();
    $autreSession = SessionCaisse::factory()->fermee()->create(['caisse_id' => $autreCaisse->id]);
    $sienne = CommandeClient::factory()->envoyeeEnCuisine()->create(['session_caisse_id' => $autreSession->id]);

    postJson('/api/sessions-caisse', ['caisse_id' => $caisse->id])->assertCreated();

    expect($sienne->fresh()->session_caisse_id)->toBe($autreSession->id);
});

it('accepte un montant_reel = 0 (caisse vide)', function () {
    actingAsSessionUser(['session_caisse.update']);
    $session = SessionCaisse::factory()->create(['fonds_initial' => 0]);

    postJson("/api/sessions-caisse/{$session->id}/fermer", tiroirCompte(0))
        ->assertOk()
        ->assertJsonPath('data.statut', 'fermee');
});

/**
 * Tout moyen de paiement accepté est vérifiable le soir même : on compte le
 * tiroir, on lit le ticket du terminal. Fermer sans compter reviendrait à
 * clôturer sans avoir contrôlé, et l'écart de ce contenant ne se verrait
 * jamais. Un mode qu'on ne saurait pas vérifier se désactive.
 */
it('refuse de fermer sans avoir compté chaque contenant (422)', function () {
    actingAsSessionUser(['session_caisse.update']);
    $session = SessionCaisse::factory()->create(['fonds_initial' => 100]);
    ModeDePaiement::factory()->create(['libelle' => 'Espèces']);

    postJson("/api/sessions-caisse/{$session->id}/fermer", [])
        ->assertStatus(422)
        ->assertJsonPath('message', fn (string $m) => str_contains($m, 'Espèces'));

    expect($session->fresh()->estOuverte())->toBeTrue();
});

it('refuse une fermeture qui oublie un seul contenant (422)', function () {
    actingAsSessionUser(['session_caisse.update']);
    $session = SessionCaisse::factory()->create(['fonds_initial' => 0]);
    $especes = ModeDePaiement::factory()->create(['libelle' => 'Espèces']);
    ModeDePaiement::factory()->horsTiroir()->create(['libelle' => 'Carte']);

    postJson("/api/sessions-caisse/{$session->id}/fermer", ['rapprochements' => [
        ['mode_de_paiement_id' => $especes->id, 'montant_constate' => 0],
    ]])
        ->assertStatus(422)
        ->assertJsonPath('message', fn (string $m) => str_contains($m, 'Carte'));
});

it('accepte un contenant compté à zéro, qui n\'est pas une absence de compte', function () {
    actingAsSessionUser(['session_caisse.update']);
    $session = SessionCaisse::factory()->create(['fonds_initial' => 0]);
    $especes = ModeDePaiement::factory()->create(['libelle' => 'Espèces']);

    postJson("/api/sessions-caisse/{$session->id}/fermer", ['rapprochements' => [
        ['mode_de_paiement_id' => $especes->id, 'montant_constate' => 0],
    ]])
        ->assertOk()
        ->assertJsonPath('data.ecart', '0.00')
        ->assertJsonPath('data.ecart_sens', 'juste');
});

/**
 * Un mode désactivé disparaît de la fermeture : c'est ainsi qu'on écarte un
 * moyen de paiement qu'on n'accepte plus, plutôt qu'en laissant sa case vide.
 */
it('ne réclame pas de compte pour un mode désactivé', function () {
    actingAsSessionUser(['session_caisse.update']);
    $session = SessionCaisse::factory()->create(['fonds_initial' => 0]);
    $especes = ModeDePaiement::factory()->create(['libelle' => 'Espèces']);
    ModeDePaiement::factory()->inactif()->create(['libelle' => 'Chèque']);

    postJson("/api/sessions-caisse/{$session->id}/fermer", ['rapprochements' => [
        ['mode_de_paiement_id' => $especes->id, 'montant_constate' => 0],
    ]])
        ->assertOk()
        ->assertJsonCount(1, 'data.rapprochements');
});

it('refuse la fermeture d\'une session déjà fermée (409)', function () {
    actingAsSessionUser(['session_caisse.update']);
    $session = SessionCaisse::factory()->fermee()->create();

    postJson("/api/sessions-caisse/{$session->id}/fermer", tiroirCompte(10))
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
