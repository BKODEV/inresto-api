<?php

declare(strict_types=1);

use App\Models\Depense;
use App\Models\FicheTechnique;
use App\Models\Inventaire;
use App\Models\MouvementStock;
use App\Models\Produit;
use App\Models\Reception;
use App\Models\SousRecette;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('génère un jeu de démonstration complet de bout en bout', function () {
    $this->artisan('inresto:demo', ['--force' => true])->assertSuccessful();

    // Définitions partagées des nouveaux modules.
    expect(SousRecette::count())->toBeGreaterThan(0)
        ->and(Produit::count())->toBeGreaterThan(0)
        ->and(FicheTechnique::where('statut', 'active')->count())->toBeGreaterThan(0);

    // Flux achats confirmés : réceptions + dépenses + entrées de stock.
    expect(Reception::count())->toBeGreaterThan(0)
        ->and(Depense::where('type', 'achat_fournisseur')->count())->toBeGreaterThan(0)
        ->and(Depense::where('type', 'charge_hors_stock')->count())->toBeGreaterThan(0);

    // Inventaires clôturés -> corrections de stock.
    expect(Inventaire::where('statut', 'cloture')->count())->toBeGreaterThan(0)
        ->and(MouvementStock::where('type', 'correction_inventaire')->count())->toBeGreaterThan(0);
});
