<?php

declare(strict_types=1);

use App\Models\Budget;
use App\Models\Depense;
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
function actingAsBudgetUser(array $permissions = []): User
{
    $user = User::factory()->create();
    foreach ($permissions as $name) {
        Permission::findOrCreate($name, 'api');
    }
    $user->givePermissionTo($permissions);
    Sanctum::actingAs($user);

    return $user;
}

/**
 * Budget du mois courant pour un site neuf, avec montant alloué et seuils.
 *
 * @param  array<string, mixed>  $attributs
 */
function budgetCourant(array $attributs = []): Budget
{
    return Budget::factory()->create(array_merge([
        'site_id' => Site::factory()->create()->id,
        'mois' => now()->startOfMonth()->toDateString(),
        'montant_alloue' => 1000,
        'seuil_alerte_75' => true,
        'seuil_alerte_90' => true,
        'seuil_alerte_100' => true,
    ], $attributs));
}

function moisCourant(): string
{
    return now()->format('Y-m');
}

// ───────────────────────── saisie charge hors stock ─────────────────────────

it('crée une charge hors stock qui apparaît dans le montant consommé', function () {
    actingAsBudgetUser(['depense.create', 'budget.view']);
    $budget = budgetCourant(['montant_alloue' => 1000]);

    postJson("/api/budgets/{$budget->id}/depenses", [
        'libelle' => 'Électricité',
        'montant' => 250,
        'date_depense' => now()->toDateString(),
    ])
        ->assertCreated()
        ->assertJsonPath('data.type', 'charge_hors_stock')
        ->assertJsonPath('data.reception_id', null);

    getJson("/api/sites/{$budget->site_id}/budgets/".moisCourant())
        ->assertOk()
        ->assertJsonPath('data.montant_consomme', '250.00')
        ->assertJsonPath('data.montant_restant', '750.00');
});

it('force le type à charge_hors_stock même si le client envoie achat_fournisseur', function () {
    actingAsBudgetUser(['depense.create']);
    $budget = budgetCourant();

    postJson("/api/budgets/{$budget->id}/depenses", [
        'libelle' => 'Tentative',
        'montant' => 100,
        'date_depense' => now()->toDateString(),
        'type' => 'achat_fournisseur',
        'reception_id' => 999,
    ])
        ->assertCreated()
        ->assertJsonPath('data.type', 'charge_hors_stock')
        ->assertJsonPath('data.reception_id', null);

    expect(Depense::where('type', 'achat_fournisseur')->count())->toBe(0);
});

it('refuse une charge hors stock sans la permission', function () {
    actingAsBudgetUser([]);
    $budget = budgetCourant();

    postJson("/api/budgets/{$budget->id}/depenses", [
        'libelle' => 'X',
        'montant' => 10,
        'date_depense' => now()->toDateString(),
    ])->assertForbidden();
});

it('valide libelle, montant > 0 et date_depense', function () {
    actingAsBudgetUser(['depense.create']);
    $budget = budgetCourant();

    postJson("/api/budgets/{$budget->id}/depenses", [
        'montant' => 0,
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['libelle', 'montant', 'date_depense']);
});

// ───────────────────────── lecture consommation ─────────────────────────

it('agrège les réceptions et les charges manuelles dans le montant consommé', function () {
    actingAsBudgetUser(['budget.view']);
    $budget = budgetCourant(['montant_alloue' => 1000]);

    // Dépense « réception » (créée ailleurs par le ReceptionService) + charge manuelle.
    Depense::factory()->achatFournisseur()->create(['budget_id' => $budget->id, 'montant' => 300]);
    Depense::factory()->create(['budget_id' => $budget->id, 'montant' => 200]);

    getJson("/api/sites/{$budget->site_id}/budgets/".moisCourant())
        ->assertOk()
        ->assertJsonPath('data.montant_consomme', '500.00')
        ->assertJsonPath('data.montant_restant', '500.00')
        ->assertJsonPath('data.pourcentage_consomme', '50.00')
        ->assertJsonCount(2, 'data.depenses');
});

// ───────────────────────── niveaux d'alerte ─────────────────────────

it('remonte « aucun » sous les seuils actifs', function () {
    actingAsBudgetUser(['budget.view']);
    $budget = budgetCourant(['montant_alloue' => 1000]);
    Depense::factory()->create(['budget_id' => $budget->id, 'montant' => 500]); // 50 %

    getJson("/api/sites/{$budget->site_id}/budgets/".moisCourant())
        ->assertJsonPath('data.niveau_alerte', 'aucun');
});

it('remonte « seuil_75 » à 75 % avec seuil_alerte_75 actif', function () {
    actingAsBudgetUser(['budget.view']);
    $budget = budgetCourant(['montant_alloue' => 1000]);
    Depense::factory()->create(['budget_id' => $budget->id, 'montant' => 800]); // 80 %

    getJson("/api/sites/{$budget->site_id}/budgets/".moisCourant())
        ->assertJsonPath('data.niveau_alerte', 'seuil_75');
});

it('remonte « seuil_90 » à 90 % avec seuil_alerte_90 actif', function () {
    actingAsBudgetUser(['budget.view']);
    $budget = budgetCourant(['montant_alloue' => 1000]);
    Depense::factory()->create(['budget_id' => $budget->id, 'montant' => 950]); // 95 %

    getJson("/api/sites/{$budget->site_id}/budgets/".moisCourant())
        ->assertJsonPath('data.niveau_alerte', 'seuil_90');
});

it('remonte « depassement » à 100 % avec seuil_alerte_100 actif', function () {
    actingAsBudgetUser(['budget.view']);
    $budget = budgetCourant(['montant_alloue' => 1000]);
    Depense::factory()->create(['budget_id' => $budget->id, 'montant' => 1100]); // 110 %

    getJson("/api/sites/{$budget->site_id}/budgets/".moisCourant())
        ->assertJsonPath('data.niveau_alerte', 'depassement');
});

it('ne remonte pas un palier désactivé même franchi', function () {
    actingAsBudgetUser(['budget.view']);
    // 95 % consommé, seuil_90 désactivé, seuil_100 actif -> « aucun » tant que < 100 %.
    $budget = budgetCourant([
        'montant_alloue' => 1000,
        'seuil_alerte_75' => false,
        'seuil_alerte_90' => false,
        'seuil_alerte_100' => true,
    ]);
    Depense::factory()->create(['budget_id' => $budget->id, 'montant' => 950]);

    getJson("/api/sites/{$budget->site_id}/budgets/".moisCourant())
        ->assertJsonPath('data.niveau_alerte', 'aucun');
});

it('considère le dépassement quand le montant alloué est nul', function () {
    actingAsBudgetUser(['budget.view']);
    $budget = budgetCourant(['montant_alloue' => 0]);
    Depense::factory()->create(['budget_id' => $budget->id, 'montant' => 50]);

    getJson("/api/sites/{$budget->site_id}/budgets/".moisCourant())
        ->assertOk()
        ->assertJsonPath('data.niveau_alerte', 'depassement')
        ->assertJsonPath('data.pourcentage_consomme', '0.00');
});

// ───────────────────────── 404 / listing ─────────────────────────

it('retourne 404 si aucun budget pour ce site/mois', function () {
    actingAsBudgetUser(['budget.view']);
    $site = Site::factory()->create();

    getJson("/api/sites/{$site->id}/budgets/2099-01")
        ->assertNotFound();
});

it('liste les budgets d\'un site avec leurs champs calculés', function () {
    actingAsBudgetUser(['budget.view']);
    $site = Site::factory()->create();
    $budget = Budget::factory()->create([
        'site_id' => $site->id,
        'mois' => now()->startOfMonth()->toDateString(),
        'montant_alloue' => 1000,
    ]);
    Depense::factory()->create(['budget_id' => $budget->id, 'montant' => 400]);

    getJson("/api/sites/{$site->id}/budgets")
        ->assertOk()
        ->assertJsonPath('data.budgets.0.montant_consomme', '400.00')
        ->assertJsonPath('data.budgets.0.niveau_alerte', 'aucun');
});
