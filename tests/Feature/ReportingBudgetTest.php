<?php

declare(strict_types=1);

use App\Models\Budget;
use App\Models\Depense;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\getJson;

uses(RefreshDatabase::class);

it('réutilise les calculs du modèle Budget pour la consommation', function () {
    actingAsReportingUser(['reporting.budget', 'reporting.global']);

    $site = Site::factory()->create();
    $budget = Budget::factory()->create([
        'site_id' => $site->id,
        'mois' => now()->startOfMonth()->toDateString(),
        'montant_alloue' => 1000,
    ]);
    Depense::factory()->create(['budget_id' => $budget->id, 'montant' => 400, 'date_depense' => now()->toDateString()]);

    $budgets = getJson('/api/reporting/budget/consommation')->assertOk()->json('data.budgets');

    expect($budgets)->toHaveCount(1);
    expect($budgets[0]['montant_consomme'])->toBe('400.00')
        ->and($budgets[0]['montant_restant'])->toBe('600.00')
        ->and($budgets[0]['pourcentage_consomme'])->toBe('40.00')
        ->and($budgets[0]['niveau_alerte'])->toBe('aucun');
});

it('liste les dépenses détaillées ventilées par type', function () {
    actingAsReportingUser(['reporting.budget', 'reporting.global']);

    $site = Site::factory()->create();
    $budget = Budget::factory()->create(['site_id' => $site->id, 'mois' => now()->startOfMonth()->toDateString()]);

    Depense::factory()->achatFournisseur()->create(['budget_id' => $budget->id, 'montant' => 300, 'date_depense' => now()->toDateString()]);
    Depense::factory()->create(['budget_id' => $budget->id, 'montant' => 200, 'date_depense' => now()->toDateString()]); // charge_hors_stock

    $data = getJson('/api/reporting/budget/depenses')->assertOk()->json('data');

    expect($data['depenses'])->toHaveCount(2)
        ->and((float) $data['totaux_par_type']['achat_fournisseur'])->toBe(300.0)
        ->and((float) $data['totaux_par_type']['charge_hors_stock'])->toBe(200.0)
        ->and((float) $data['total'])->toBe(500.0);
});

it('inclut les dépenses tombant sur le dernier jour de la période', function () {
    actingAsReportingUser(['reporting.budget', 'reporting.global']);

    $site = Site::factory()->create();
    $budget = Budget::factory()->create(['site_id' => $site->id, 'mois' => '2026-03-01']);
    Depense::factory()->create(['budget_id' => $budget->id, 'montant' => 150, 'date_depense' => '2026-03-31']);

    // Borne haute = jour même de la dépense : elle doit être comptée (bornes incluses).
    $data = getJson('/api/reporting/budget/depenses?periode=plage&date_debut=2026-03-01&date_fin=2026-03-31')
        ->assertOk()
        ->json('data');

    expect($data['depenses'])->toHaveCount(1)
        ->and((float) $data['total'])->toBe(150.0);
});
