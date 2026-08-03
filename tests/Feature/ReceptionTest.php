<?php

declare(strict_types=1);

use App\Enums\StatutCommandeFournisseur;
use App\Exceptions\CommandeFournisseurException;
use App\Exceptions\ReceptionException;
use App\Models\Budget;
use App\Models\CatalogueFournisseur;
use App\Models\CommandeFournisseur;
use App\Models\Depense;
use App\Models\Fournisseur;
use App\Models\LigneReception;
use App\Models\MatierePremiere;
use App\Models\MouvementStock;
use App\Models\Reception;
use App\Models\Site;
use App\Models\Stock;
use App\Models\UniteDeMesure;
use App\Models\User;
use App\Services\CommandeFournisseurService;
use App\Services\ReceptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;

use function Pest\Laravel\postJson;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->service = app(ReceptionService::class);
    $this->userId = User::factory()->create()->id;
});

/**
 * Crée un site + un budget actif pour le mois courant.
 */
function siteAvecBudget(): Site
{
    $site = Site::factory()->create();
    Budget::factory()->create([
        'site_id' => $site->id,
        'mois' => now()->startOfMonth()->toDateString(),
    ]);

    return $site;
}

/**
 * Crée une matière + son entrée au catalogue d'un fournisseur (facteur de
 * conversion fourni).
 *
 * @return array{matiere: MatierePremiere, unite: UniteDeMesure}
 */
function matiereCataloguee(Fournisseur $fournisseur, float $facteur = 1, float $prixCatalogue = 1): array
{
    $unite = UniteDeMesure::factory()->create();
    $matiere = MatierePremiere::factory()->create(['unite_de_mesure_id' => $unite->id]);

    CatalogueFournisseur::factory()->create([
        'fournisseur_id' => $fournisseur->id,
        'matiere_premiere_id' => $matiere->id,
        'unite_achat_id' => $unite->id,
        'prix_unitaire' => $prixCatalogue,
        'qte_par_unite_achat' => $facteur,
    ]);

    return ['matiere' => $matiere, 'unite' => $unite];
}

/**
 * Commande passée au statut « envoyee » avec les lignes fournies.
 *
 * @param  array<int, array<string, mixed>>  $lignes
 */
function commandeEnvoyee(Fournisseur $fournisseur, array $lignes): CommandeFournisseur
{
    $service = app(CommandeFournisseurService::class);
    $commande = $service->creer(['fournisseur_id' => $fournisseur->id], $lignes, User::factory()->create()->id);
    $service->marquerEnvoyee($commande);

    return $commande->fresh();
}

// ───────────────────────── réception complète ─────────────────────────

it('confirme une réception complète : commande cloturée, stock du site et dépense', function () {
    $fournisseur = Fournisseur::factory()->create();
    $site = siteAvecBudget();
    ['matiere' => $matiere, 'unite' => $unite] = matiereCataloguee($fournisseur, facteur: 2);

    $commande = commandeEnvoyee($fournisseur, [[
        'matiere_premiere_id' => $matiere->id,
        'unite_id' => $unite->id,
        'quantite_commandee' => 10,
        'prix_unitaire_previsionnel' => 4,
    ]]);

    $reception = Reception::factory()->create([
        'commande_fournisseur_id' => $commande->id,
        'site_id' => $site->id,
    ]);

    $this->service->confirmer($reception, [[
        'matiere_premiere_id' => $matiere->id,
        'unite_id' => $unite->id,
        'quantite_recue' => 10,
        'prix_unitaire_reel' => 5,
    ]], $this->userId);

    expect($commande->fresh()->statut)->toBe(StatutCommandeFournisseur::Cloturee);

    // Conversion : 10 unités d'achat × facteur 2 = 20 en stockage ; 5 / 2 = 2.5 le PMP.
    $stock = Stock::where('matiere_premiere_id', $matiere->id)->where('site_id', $site->id)->first();
    expect((float) $stock->stock_courant)->toBe(20.0)
        ->and((float) $stock->pmp)->toBe(2.5);

    // Montant réel facturé (en unité d'achat) : 10 × 5 = 50.
    expect((float) $reception->fresh()->montant_reel)->toBe(50.0);

    $depense = Depense::where('reception_id', $reception->id)->first();
    expect($depense)->not->toBeNull()
        ->and($depense->type)->toBe('achat_fournisseur')
        ->and((float) $depense->montant)->toBe(50.0);
});

it('rattache le mouvement de stock à la ligne de réception (reference_type = lignes_reception)', function () {
    $fournisseur = Fournisseur::factory()->create();
    $site = siteAvecBudget();
    ['matiere' => $matiere, 'unite' => $unite] = matiereCataloguee($fournisseur);

    $commande = commandeEnvoyee($fournisseur, [[
        'matiere_premiere_id' => $matiere->id,
        'unite_id' => $unite->id,
        'quantite_commandee' => 10,
        'prix_unitaire_previsionnel' => 4,
    ]]);
    $reception = Reception::factory()->create(['commande_fournisseur_id' => $commande->id, 'site_id' => $site->id]);

    $this->service->confirmer($reception, [[
        'matiere_premiere_id' => $matiere->id,
        'unite_id' => $unite->id,
        'quantite_recue' => 10,
        'prix_unitaire_reel' => 4,
    ]], $this->userId);

    $ligne = $reception->lignes()->first();
    $mouvement = MouvementStock::where('reference_type', 'lignes_reception')
        ->where('reference_id', $ligne->id)
        ->first();

    expect($mouvement)->not->toBeNull()
        ->and($mouvement->type->value)->toBe('entree_reception')
        // La relation polymorphe se résout bien vers la ligne de réception.
        ->and($mouvement->reference)->toBeInstanceOf(LigneReception::class);
});

// ───────────────────────── réception partielle ─────────────────────────

it('passe la commande en partiellement_recue quand la réception est incomplète', function () {
    $fournisseur = Fournisseur::factory()->create();
    $site = siteAvecBudget();
    ['matiere' => $matiere, 'unite' => $unite] = matiereCataloguee($fournisseur);

    $commande = commandeEnvoyee($fournisseur, [[
        'matiere_premiere_id' => $matiere->id,
        'unite_id' => $unite->id,
        'quantite_commandee' => 10,
        'prix_unitaire_previsionnel' => 4,
    ]]);

    $reception = Reception::factory()->create([
        'commande_fournisseur_id' => $commande->id,
        'site_id' => $site->id,
    ]);

    $this->service->confirmer($reception, [[
        'matiere_premiere_id' => $matiere->id,
        'unite_id' => $unite->id,
        'quantite_recue' => 5,
        'prix_unitaire_reel' => 4,
    ]], $this->userId);

    expect($commande->fresh()->statut)->toBe(StatutCommandeFournisseur::PartiellementRecue);
});

// ───────────────────────── deux réceptions cumulées ─────────────────────────

it('clôture la commande à la 2e réception quand le cumul atteint la quantité commandée', function () {
    $fournisseur = Fournisseur::factory()->create();
    $site = siteAvecBudget();
    ['matiere' => $matiere, 'unite' => $unite] = matiereCataloguee($fournisseur);

    $commande = commandeEnvoyee($fournisseur, [[
        'matiere_premiere_id' => $matiere->id,
        'unite_id' => $unite->id,
        'quantite_commandee' => 10,
        'prix_unitaire_previsionnel' => 4,
    ]]);

    $ligneRecue = fn (float $qte) => [[
        'matiere_premiere_id' => $matiere->id,
        'unite_id' => $unite->id,
        'quantite_recue' => $qte,
        'prix_unitaire_reel' => 4,
    ]];

    $r1 = Reception::factory()->create(['commande_fournisseur_id' => $commande->id, 'site_id' => $site->id]);
    $this->service->confirmer($r1, $ligneRecue(4), $this->userId);
    expect($commande->fresh()->statut)->toBe(StatutCommandeFournisseur::PartiellementRecue);

    $r2 = Reception::factory()->create(['commande_fournisseur_id' => $commande->id, 'site_id' => $site->id]);
    $this->service->confirmer($r2, $ligneRecue(6), $this->userId);
    expect($commande->fresh()->statut)->toBe(StatutCommandeFournisseur::Cloturee);

    $stock = Stock::where('matiere_premiere_id', $matiere->id)->where('site_id', $site->id)->first();
    expect((float) $stock->stock_courant)->toBe(10.0);
});

// ───────────────────────── budget de site absent ─────────────────────────

it('annule tout si aucun budget actif pour le site (ni stock ni dépense)', function () {
    $fournisseur = Fournisseur::factory()->create();
    $site = Site::factory()->create(); // pas de budget
    ['matiere' => $matiere, 'unite' => $unite] = matiereCataloguee($fournisseur);

    $commande = commandeEnvoyee($fournisseur, [[
        'matiere_premiere_id' => $matiere->id,
        'unite_id' => $unite->id,
        'quantite_commandee' => 10,
        'prix_unitaire_previsionnel' => 4,
    ]]);

    $reception = Reception::factory()->create(['commande_fournisseur_id' => $commande->id, 'site_id' => $site->id]);

    expect(fn () => $this->service->confirmer($reception, [[
        'matiere_premiere_id' => $matiere->id,
        'unite_id' => $unite->id,
        'quantite_recue' => 5,
        'prix_unitaire_reel' => 4,
    ]], $this->userId))->toThrow(ReceptionException::class);

    expect(LigneReception::count())->toBe(0)
        ->and(MouvementStock::count())->toBe(0)
        ->and(Stock::count())->toBe(0)
        ->and(Depense::count())->toBe(0)
        ->and($commande->fresh()->statut)->toBe(StatutCommandeFournisseur::Envoyee);
});

// ───────────────────────── catalogue absent ─────────────────────────

it('annule tout si le facteur de conversion (catalogue) est manquant', function () {
    $fournisseur = Fournisseur::factory()->create();
    $site = siteAvecBudget();
    ['matiere' => $matiere] = matiereCataloguee($fournisseur);

    $commande = commandeEnvoyee($fournisseur, [[
        'matiere_premiere_id' => $matiere->id,
        'quantite_commandee' => 10,
        'prix_unitaire_previsionnel' => 4,
    ]]);

    // Cas limite : le catalogue disparaît APRÈS l'envoi (suppression directe en
    // base, hors du garde-fou API). La réception ne trouve plus ni l'unité ni le
    // facteur -> tout est annulé (transaction).
    CatalogueFournisseur::where('fournisseur_id', $fournisseur->id)
        ->where('matiere_premiere_id', $matiere->id)
        ->delete();

    $reception = Reception::factory()->create(['commande_fournisseur_id' => $commande->id, 'site_id' => $site->id]);

    expect(fn () => $this->service->confirmer($reception, [[
        'matiere_premiere_id' => $matiere->id,
        'quantite_recue' => 5,
        'prix_unitaire_reel' => 4,
    ]], $this->userId))->toThrow(ReceptionException::class);

    expect(LigneReception::count())->toBe(0)
        ->and(MouvementStock::count())->toBe(0)
        ->and(Depense::count())->toBe(0);
});

it('estampe l\'unité d\'achat du catalogue sur la ligne de réception', function () {
    $fournisseur = Fournisseur::factory()->create();
    $site = siteAvecBudget();
    $uniteAchat = UniteDeMesure::factory()->create(['symbole' => 'cagette']);
    $matiere = MatierePremiere::factory()->create();
    CatalogueFournisseur::factory()->create([
        'fournisseur_id' => $fournisseur->id,
        'matiere_premiere_id' => $matiere->id,
        'unite_achat_id' => $uniteAchat->id,
        'qte_par_unite_achat' => 2,
        'prix_unitaire' => 1,
    ]);

    $commande = commandeEnvoyee($fournisseur, [[
        'matiere_premiere_id' => $matiere->id,
        'quantite_commandee' => 10,
        'prix_unitaire_previsionnel' => 4,
    ]]);
    $reception = Reception::factory()->create(['commande_fournisseur_id' => $commande->id, 'site_id' => $site->id]);

    $this->service->confirmer($reception, [[
        'matiere_premiere_id' => $matiere->id,
        // Aucune unité fournie : le serveur pose celle du catalogue.
        'quantite_recue' => 10,
        'prix_unitaire_reel' => 4,
    ]], $this->userId);

    expect((int) $reception->lignes()->first()->unite_id)->toBe($uniteAchat->id);
});

// ───────────────────────── sur-livraison ─────────────────────────

it('autorise une sur-livraison : le réel est stocké sans blocage', function () {
    $fournisseur = Fournisseur::factory()->create();
    $site = siteAvecBudget();
    ['matiere' => $matiere, 'unite' => $unite] = matiereCataloguee($fournisseur);

    $commande = commandeEnvoyee($fournisseur, [[
        'matiere_premiere_id' => $matiere->id,
        'unite_id' => $unite->id,
        'quantite_commandee' => 10,
        'prix_unitaire_previsionnel' => 4,
    ]]);

    $reception = Reception::factory()->create(['commande_fournisseur_id' => $commande->id, 'site_id' => $site->id]);

    $this->service->confirmer($reception, [[
        'matiere_premiere_id' => $matiere->id,
        'unite_id' => $unite->id,
        'quantite_recue' => 12,
        'prix_unitaire_reel' => 4,
    ]], $this->userId);

    $stock = Stock::where('matiere_premiere_id', $matiere->id)->where('site_id', $site->id)->first();
    expect((float) $stock->stock_courant)->toBe(12.0)
        ->and($commande->fresh()->statut)->toBe(StatutCommandeFournisseur::Cloturee);
});

// ───────────────────────── tolérance 1 % ─────────────────────────

it('clôture la commande quand l\'écart est dans la tolérance de 1 %', function () {
    $fournisseur = Fournisseur::factory()->create();
    $site = siteAvecBudget();
    ['matiere' => $matiere, 'unite' => $unite] = matiereCataloguee($fournisseur);

    $commande = commandeEnvoyee($fournisseur, [[
        'matiere_premiere_id' => $matiere->id,
        'unite_id' => $unite->id,
        'quantite_commandee' => 100,
        'prix_unitaire_previsionnel' => 4,
    ]]);

    $reception = Reception::factory()->create(['commande_fournisseur_id' => $commande->id, 'site_id' => $site->id]);

    // 99 reçus sur 100 commandés = 1 % d'écart -> considéré intégral.
    $this->service->confirmer($reception, [[
        'matiere_premiere_id' => $matiere->id,
        'unite_id' => $unite->id,
        'quantite_recue' => 99,
        'prix_unitaire_reel' => 4,
    ]], $this->userId);

    expect($commande->fresh()->statut)->toBe(StatutCommandeFournisseur::Cloturee);
});

// ───────────────────────── deux sites ─────────────────────────

it('impacte indépendamment le stock de chaque site sur deux réceptions', function () {
    $fournisseur = Fournisseur::factory()->create();
    $siteA = siteAvecBudget();
    $siteB = siteAvecBudget();
    ['matiere' => $matiere, 'unite' => $unite] = matiereCataloguee($fournisseur);

    $commande = commandeEnvoyee($fournisseur, [[
        'matiere_premiere_id' => $matiere->id,
        'unite_id' => $unite->id,
        'quantite_commandee' => 10,
        'prix_unitaire_previsionnel' => 4,
    ]]);

    $ligneRecue = fn (float $qte) => [[
        'matiere_premiere_id' => $matiere->id,
        'unite_id' => $unite->id,
        'quantite_recue' => $qte,
        'prix_unitaire_reel' => 4,
    ]];

    $rA = Reception::factory()->create(['commande_fournisseur_id' => $commande->id, 'site_id' => $siteA->id]);
    $this->service->confirmer($rA, $ligneRecue(4), $this->userId);

    $rB = Reception::factory()->create(['commande_fournisseur_id' => $commande->id, 'site_id' => $siteB->id]);
    $this->service->confirmer($rB, $ligneRecue(6), $this->userId);

    $stockA = Stock::where('matiere_premiere_id', $matiere->id)->where('site_id', $siteA->id)->first();
    $stockB = Stock::where('matiere_premiere_id', $matiere->id)->where('site_id', $siteB->id)->first();

    expect((float) $stockA->stock_courant)->toBe(4.0)
        ->and((float) $stockB->stock_courant)->toBe(6.0)
        ->and($commande->fresh()->statut)->toBe(StatutCommandeFournisseur::Cloturee);
});

// ───────────────────────── statut commande incompatible ─────────────────────────

it('refuse une réception sur une commande en brouillon', function () {
    $fournisseur = Fournisseur::factory()->create();
    $site = siteAvecBudget();
    ['matiere' => $matiere, 'unite' => $unite] = matiereCataloguee($fournisseur);

    $commande = app(CommandeFournisseurService::class)->creer(
        ['fournisseur_id' => $fournisseur->id],
        [[
            'matiere_premiere_id' => $matiere->id,
            'unite_id' => $unite->id,
            'quantite_commandee' => 10,
            'prix_unitaire_previsionnel' => 4,
        ]],
        $this->userId,
    ); // reste en brouillon

    $reception = Reception::factory()->create(['commande_fournisseur_id' => $commande->id, 'site_id' => $site->id]);

    expect(fn () => $this->service->confirmer($reception, [[
        'matiere_premiere_id' => $matiere->id,
        'unite_id' => $unite->id,
        'quantite_recue' => 5,
        'prix_unitaire_reel' => 4,
    ]], $this->userId))->toThrow(ReceptionException::class);
});

// ───────────────────────── annulation commande réceptionnée ─────────────────────────

it('empêche l\'annulation d\'une commande déjà réceptionnée', function () {
    $fournisseur = Fournisseur::factory()->create();
    $site = siteAvecBudget();
    ['matiere' => $matiere, 'unite' => $unite] = matiereCataloguee($fournisseur);

    $commande = commandeEnvoyee($fournisseur, [[
        'matiere_premiere_id' => $matiere->id,
        'unite_id' => $unite->id,
        'quantite_commandee' => 10,
        'prix_unitaire_previsionnel' => 4,
    ]]);

    $reception = Reception::factory()->create(['commande_fournisseur_id' => $commande->id, 'site_id' => $site->id]);
    $this->service->confirmer($reception, [[
        'matiere_premiere_id' => $matiere->id,
        'unite_id' => $unite->id,
        'quantite_recue' => 5,
        'prix_unitaire_reel' => 4,
    ]], $this->userId);

    expect(fn () => app(CommandeFournisseurService::class)->annuler($commande->fresh()))
        ->toThrow(CommandeFournisseurException::class);
});

// ───────────────────────── API ─────────────────────────

/**
 * @param  string[]  $permissions
 */
function actingAsReceptionUser(array $permissions = []): User
{
    $user = User::factory()->create();
    foreach ($permissions as $name) {
        Permission::findOrCreate($name, 'api');
    }
    $user->givePermissionTo($permissions);
    Sanctum::actingAs($user);

    return $user;
}

it('crée puis confirme une réception via l\'API', function () {
    $user = actingAsReceptionUser(['reception.create', 'reception.update']);

    $fournisseur = Fournisseur::factory()->create();
    $site = siteAvecBudget();
    rattacherAuxSites($user, $site);
    ['matiere' => $matiere, 'unite' => $unite] = matiereCataloguee($fournisseur, facteur: 2);
    $commande = commandeEnvoyee($fournisseur, [[
        'matiere_premiere_id' => $matiere->id,
        'unite_id' => $unite->id,
        'quantite_commandee' => 10,
        'prix_unitaire_previsionnel' => 4,
    ]]);

    $creation = postJson('/api/receptions', [
        'commande_fournisseur_id' => $commande->id,
        'site_id' => $site->id,
    ])->assertCreated()->json('data');

    postJson("/api/receptions/{$creation['id']}/confirmer", [
        'lignes' => [[
            'matiere_premiere_id' => $matiere->id,
            'unite_id' => $unite->id,
            'quantite_recue' => 10,
            'prix_unitaire_reel' => 5,
        ]],
    ])
        ->assertOk()
        ->assertJsonPath('data.montant_reel', '50.00');

    expect($commande->fresh()->statut)->toBe(StatutCommandeFournisseur::Cloturee);
});

it('refuse la création d\'une réception sans authentification', function () {
    postJson('/api/receptions', [])->assertUnauthorized();
});

it('refuse une réception sur une matière absente de la commande (API)', function () {
    $user = actingAsReceptionUser(['reception.create', 'reception.update']);

    $fournisseur = Fournisseur::factory()->create();
    $site = siteAvecBudget();
    rattacherAuxSites($user, $site);
    ['matiere' => $matiere, 'unite' => $unite] = matiereCataloguee($fournisseur);
    $autre = matiereCataloguee($fournisseur);
    $commande = commandeEnvoyee($fournisseur, [[
        'matiere_premiere_id' => $matiere->id,
        'unite_id' => $unite->id,
        'quantite_commandee' => 10,
        'prix_unitaire_previsionnel' => 4,
    ]]);

    $reception = Reception::factory()->create(['commande_fournisseur_id' => $commande->id, 'site_id' => $site->id]);

    postJson("/api/receptions/{$reception->id}/confirmer", [
        'lignes' => [[
            'matiere_premiere_id' => $autre['matiere']->id,
            'unite_id' => $autre['unite']->id,
            'quantite_recue' => 5,
            'prix_unitaire_reel' => 4,
        ]],
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('lignes.0.matiere_premiere_id');
});

// ───────────────────────── unicité de l'article ─────────────────────────

it('refuse deux lignes sur le même article dans une réception (API)', function () {
    $user = actingAsReceptionUser(['reception.create', 'reception.update']);

    $fournisseur = Fournisseur::factory()->create();
    $site = siteAvecBudget();
    rattacherAuxSites($user, $site);
    ['matiere' => $matiere, 'unite' => $unite] = matiereCataloguee($fournisseur);
    $commande = commandeEnvoyee($fournisseur, [[
        'matiere_premiere_id' => $matiere->id,
        'unite_id' => $unite->id,
        'quantite_commandee' => 10,
        'prix_unitaire_previsionnel' => 4,
    ]]);

    $reception = Reception::factory()->create(['commande_fournisseur_id' => $commande->id, 'site_id' => $site->id]);

    postJson("/api/receptions/{$reception->id}/confirmer", [
        'lignes' => [
            ['matiere_premiere_id' => $matiere->id, 'quantite_recue' => 4, 'prix_unitaire_reel' => 4],
            ['matiere_premiere_id' => $matiere->id, 'quantite_recue' => 6, 'prix_unitaire_reel' => 4],
        ],
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('lignes.1.matiere_premiere_id');

    expect(LigneReception::where('reception_id', $reception->id)->count())->toBe(0);
});

it('refuse au service un article en doublon, sans toucher au stock', function () {
    $fournisseur = Fournisseur::factory()->create();
    $site = siteAvecBudget();
    ['matiere' => $matiere, 'unite' => $unite] = matiereCataloguee($fournisseur);
    $commande = commandeEnvoyee($fournisseur, [[
        'matiere_premiere_id' => $matiere->id,
        'unite_id' => $unite->id,
        'quantite_commandee' => 10,
        'prix_unitaire_previsionnel' => 4,
    ]]);

    $reception = Reception::factory()->create(['commande_fournisseur_id' => $commande->id, 'site_id' => $site->id]);

    expect(fn () => $this->service->confirmer($reception, [
        ['matiere_premiere_id' => $matiere->id, 'quantite_recue' => 4, 'prix_unitaire_reel' => 4],
        ['matiere_premiere_id' => $matiere->id, 'quantite_recue' => 6, 'prix_unitaire_reel' => 4],
    ], $this->userId))->toThrow(ReceptionException::class);

    expect(Stock::where('matiere_premiere_id', $matiere->id)->count())->toBe(0)
        ->and(Depense::count())->toBe(0);
});
