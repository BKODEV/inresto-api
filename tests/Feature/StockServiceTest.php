<?php

declare(strict_types=1);

use App\Enums\MouvementType;
use App\Exceptions\StockException;
use App\Models\Fournisseur;
use App\Models\MatierePremiere;
use App\Models\MouvementStock;
use App\Models\Site;
use App\Models\Stock;
use App\Models\User;
use App\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->service = app(StockService::class);
    $this->site = Site::factory()->create();
});

/** Crée une matière active. */
function matiereActive(): MatierePremiere
{
    return MatierePremiere::factory()->create();
}

/** Lit la ligne de stock du couple (matière, site). */
function lireStock(int $matiereId, int $siteId): ?Stock
{
    return Stock::query()
        ->where('matiere_premiere_id', $matiereId)
        ->where('site_id', $siteId)
        ->first();
}

// ───────────────────────────── PMP ─────────────────────────────

it('initialise le pmp au prix réel sur une première entrée (stock à zéro)', function () {
    $matiere = matiereActive();

    $this->service->entree($matiere->id, $this->site->id, 10, 2.00, MouvementType::EntreeReception, null);

    $stock = lireStock($matiere->id, $this->site->id);
    expect((float) $stock->stock_courant)->toBe(10.0)
        ->and((float) $stock->pmp)->toBe(2.00);
});

it('recalcule le pmp en moyenne pondérée sur une entrée avec stock existant', function () {
    $matiere = matiereActive();

    $this->service->entree($matiere->id, $this->site->id, 10, 2.00, MouvementType::EntreeReception, null);
    $this->service->entree($matiere->id, $this->site->id, 10, 4.00, MouvementType::EntreeReception, null);

    $stock = lireStock($matiere->id, $this->site->id);
    // (10*2 + 10*4) / 20 = 3.00
    expect((float) $stock->pmp)->toBe(3.00)
        ->and((float) $stock->stock_courant)->toBe(20.0);
});

it('garde le prix réel comme pmp quand le stock est négatif (pas de pondération)', function () {
    $matiere = matiereActive();

    // stock 5 @ pmp 2, puis vente de 8 -> stock -3, pmp toujours 2
    $this->service->entree($matiere->id, $this->site->id, 5, 2.00, MouvementType::EntreeReception, null);
    $this->service->sortie($matiere->id, $this->site->id, 8, MouvementType::SortieVente, null);

    // Entrée alors que stock <= 0 : le pmp doit valoir le nouveau prix réel,
    // pas une moyenne pondérée (qui donnerait 12.00).
    $this->service->entree($matiere->id, $this->site->id, 10, 9.00, MouvementType::EntreeReception, null);

    $stock = lireStock($matiere->id, $this->site->id);
    expect((float) $stock->pmp)->toBe(9.00)
        ->and((float) $stock->stock_courant)->toBe(7.0);
});

it('conserve la précision du pmp sous le centime (4 décimales)', function () {
    $matiere = matiereActive();

    $this->service->entree($matiere->id, $this->site->id, 3, 10.00, MouvementType::EntreeReception, null);
    $this->service->entree($matiere->id, $this->site->id, 1, 10.01, MouvementType::EntreeReception, null);

    $stock = lireStock($matiere->id, $this->site->id);
    // (3*10 + 1*10.01) / 4 = 10.0025 — serait arrondi à 10.00 en decimal:2
    expect((float) $stock->pmp)->toBe(10.0025);
});

it('produit un pmp final correct après plusieurs entrées (concurrence sérialisée)', function () {
    $matiere = matiereActive();

    $this->service->entree($matiere->id, $this->site->id, 10, 2.00, MouvementType::EntreeReception, null);
    $this->service->entree($matiere->id, $this->site->id, 30, 6.00, MouvementType::EntreeReception, null);

    $stock = lireStock($matiere->id, $this->site->id);
    // (10*2 + 30*6) / 40 = 5.00
    expect((float) $stock->pmp)->toBe(5.00)
        ->and((float) $stock->stock_courant)->toBe(40.0)
        ->and(MouvementStock::where('matiere_premiere_id', $matiere->id)->count())->toBe(2);
});

// ───────────────────────────── Multi-site ─────────────────────────────

it('tient des stocks et des pmp totalement indépendants par site', function () {
    $matiere = matiereActive();
    $siteA = $this->site;
    $siteB = Site::factory()->create();

    // Site A : 10 @ 2  → stock 10, pmp 2
    $this->service->entree($matiere->id, $siteA->id, 10, 2.00, MouvementType::EntreeReception, null);
    // Site B : 5 @ 8   → stock 5, pmp 8 (aucun impact sur A)
    $this->service->entree($matiere->id, $siteB->id, 5, 8.00, MouvementType::EntreeReception, null);
    // Sortie côté A uniquement
    $this->service->sortie($matiere->id, $siteA->id, 4, MouvementType::SortieVente, null);

    $stockA = lireStock($matiere->id, $siteA->id);
    $stockB = lireStock($matiere->id, $siteB->id);

    expect((float) $stockA->stock_courant)->toBe(6.0)
        ->and((float) $stockA->pmp)->toBe(2.00)
        ->and((float) $stockB->stock_courant)->toBe(5.0)
        ->and((float) $stockB->pmp)->toBe(8.00);

    // Chaque mouvement porte le bon site.
    expect(MouvementStock::where('site_id', $siteA->id)->count())->toBe(2)
        ->and(MouvementStock::where('site_id', $siteB->id)->count())->toBe(1);
});

it('crée la ligne de stock du site à la volée lors de la première entrée', function () {
    $matiere = matiereActive();

    expect(lireStock($matiere->id, $this->site->id))->toBeNull();

    $this->service->entree($matiere->id, $this->site->id, 5, 3.00, MouvementType::EntreeReception, null);

    $stock = lireStock($matiere->id, $this->site->id);
    expect($stock)->toBeInstanceOf(Stock::class)
        ->and((float) $stock->stock_courant)->toBe(5.0);
});

// ───────────────────────────── Entrée ─────────────────────────────

it('enregistre un mouvement d\'entrée positif avec le prix comme pmp_au_moment', function () {
    $matiere = matiereActive();

    $mouvement = $this->service->entree($matiere->id, $this->site->id, 7, 3.50, MouvementType::EntreeReception, null);

    expect($mouvement->type)->toBe(MouvementType::EntreeReception)
        ->and((float) $mouvement->quantite)->toBe(7.0)
        ->and((float) $mouvement->pmp_au_moment)->toBe(3.50)
        ->and($mouvement->site_id)->toBe($this->site->id);
});

it('rejette une entrée avec une quantité non positive', function () {
    $matiere = matiereActive();

    $this->service->entree($matiere->id, $this->site->id, 0, 3.00, MouvementType::EntreeReception, null);
})->throws(StockException::class);

it('rejette une entrée avec un prix non positif', function () {
    $matiere = matiereActive();

    $this->service->entree($matiere->id, $this->site->id, 5, 0, MouvementType::EntreeReception, null);
})->throws(StockException::class);

it('rejette une entrée sur une matière inactive', function () {
    $matiere = MatierePremiere::factory()->inactif()->create();

    $this->service->entree($matiere->id, $this->site->id, 5, 3.00, MouvementType::EntreeReception, null);
})->throws(StockException::class);

it('rejette une entrée sur une matière introuvable', function () {
    $this->service->entree(99999, $this->site->id, 5, 3.00, MouvementType::EntreeReception, null);
})->throws(StockException::class);

it('rejette une entrée dont le type n\'est pas une entrée', function () {
    $matiere = matiereActive();

    $this->service->entree($matiere->id, $this->site->id, 5, 3.00, MouvementType::Perte, null);
})->throws(StockException::class);

// ───────────────────────────── Sortie ─────────────────────────────

it('enregistre une sortie en quantité positive et décrémente le stock', function () {
    $matiere = matiereActive();
    $this->service->entree($matiere->id, $this->site->id, 10, 4.00, MouvementType::EntreeReception, null);

    $mouvement = $this->service->sortie($matiere->id, $this->site->id, 3, MouvementType::SortieVente, null);

    $stock = lireStock($matiere->id, $this->site->id);
    expect((float) $mouvement->quantite)->toBe(3.0)
        ->and((float) $mouvement->pmp_au_moment)->toBe(4.00) // pmp courant figé
        ->and((float) $stock->stock_courant)->toBe(7.0)
        ->and((float) $stock->pmp)->toBe(4.00); // pmp inchangé par une sortie
});

it('ne bloque jamais une vente même en stock négatif', function () {
    $matiere = matiereActive();
    $this->service->entree($matiere->id, $this->site->id, 2, 1.00, MouvementType::EntreeReception, null);

    $mouvement = $this->service->sortie($matiere->id, $this->site->id, 5, MouvementType::SortieVente, null);

    $stock = lireStock($matiere->id, $this->site->id);
    expect((float) $stock->stock_courant)->toBe(-3.0)
        ->and($mouvement)->toBeInstanceOf(MouvementStock::class);
});

it('rejette une sortie non-vente en stock négatif quand le négatif est interdit', function () {
    config()->set('inresto.stock.autoriser_stock_negatif', false);

    $matiere = matiereActive();
    $this->service->entree($matiere->id, $this->site->id, 2, 1.00, MouvementType::EntreeReception, null);

    $this->service->sortie($matiere->id, $this->site->id, 5, MouvementType::Perte, null);
})->throws(StockException::class);

it('autorise quand même une vente en stock négatif lorsque le négatif est interdit', function () {
    config()->set('inresto.stock.autoriser_stock_negatif', false);

    $matiere = matiereActive();
    $this->service->entree($matiere->id, $this->site->id, 2, 1.00, MouvementType::EntreeReception, null);

    $this->service->sortie($matiere->id, $this->site->id, 5, MouvementType::SortieVente, null);

    $stock = lireStock($matiere->id, $this->site->id);
    expect((float) $stock->stock_courant)->toBe(-3.0);
});

it('rejette une sortie dont le type n\'est pas une sortie', function () {
    $matiere = matiereActive();

    $this->service->sortie($matiere->id, $this->site->id, 1, MouvementType::EntreeReception, null);
})->throws(StockException::class);

it('rejette une sortie avec une quantité non positive', function () {
    $matiere = matiereActive();

    $this->service->sortie($matiere->id, $this->site->id, -1, MouvementType::SortieVente, null);
})->throws(StockException::class);

// ─────────────────────── Correction d'inventaire ───────────────────────

it('corrige un inventaire avec un écart positif sans toucher le pmp', function () {
    $matiere = matiereActive();
    $this->service->entree($matiere->id, $this->site->id, 10, 5.00, MouvementType::EntreeReception, null);

    $mouvement = $this->service->correctionInventaire($matiere->id, $this->site->id, 12, null);

    $stock = lireStock($matiere->id, $this->site->id);
    expect($mouvement)->toBeInstanceOf(MouvementStock::class)
        ->and($mouvement->type)->toBe(MouvementType::CorrectionInventaire)
        ->and((float) $mouvement->quantite)->toBe(2.0) // abs(ecart), positive
        ->and((float) $stock->stock_courant)->toBe(12.0)
        ->and((float) $stock->pmp)->toBe(5.00);
});

it('corrige un inventaire avec un écart négatif', function () {
    $matiere = matiereActive();
    $this->service->entree($matiere->id, $this->site->id, 10, 5.00, MouvementType::EntreeReception, null);

    $mouvement = $this->service->correctionInventaire($matiere->id, $this->site->id, 7, null);

    $stock = lireStock($matiere->id, $this->site->id);
    expect((float) $mouvement->quantite)->toBe(3.0) // abs(-3)
        ->and((float) $stock->stock_courant)->toBe(7.0);
});

it('ne crée aucun mouvement et retourne null quand l\'écart d\'inventaire est nul', function () {
    $matiere = matiereActive();
    $this->service->entree($matiere->id, $this->site->id, 10, 5.00, MouvementType::EntreeReception, null);

    $avant = MouvementStock::where('matiere_premiere_id', $matiere->id)->count();
    $resultat = $this->service->correctionInventaire($matiere->id, $this->site->id, 10, null);

    $stock = lireStock($matiere->id, $this->site->id);
    expect($resultat)->toBeNull()
        ->and(MouvementStock::where('matiere_premiere_id', $matiere->id)->count())->toBe($avant)
        ->and((float) $stock->stock_courant)->toBe(10.0);
});

it('rejette une quantité physique négative en correction d\'inventaire', function () {
    $matiere = matiereActive();

    $this->service->correctionInventaire($matiere->id, $this->site->id, -1, null);
})->throws(StockException::class);

// ─────────────────────── Référence & utilisateur ───────────────────────

it('attache la référence polymorphe et l\'utilisateur au mouvement', function () {
    $matiere = matiereActive();
    $user = User::factory()->create();
    $reference = Fournisseur::factory()->create();

    $mouvement = $this->service->entree($matiere->id, $this->site->id, 5, 2.00, MouvementType::EntreeReception, $user->id, $reference);

    expect($mouvement->user_id)->toBe($user->id)
        ->and($mouvement->reference_type)->toBe(Fournisseur::class)
        ->and($mouvement->reference_id)->toBe($reference->id)
        ->and($mouvement->reference)->toBeInstanceOf(Fournisseur::class)
        ->and($mouvement->reference->is($reference))->toBeTrue();
});

it('expose le sens du type de mouvement', function () {
    expect(MouvementType::EntreeReception->sens())->toBe('entree')
        ->and(MouvementType::SortieVente->sens())->toBe('sortie')
        ->and(MouvementType::Perte->sens())->toBe('sortie')
        ->and(MouvementType::CorrectionInventaire->sens())->toBe('neutre');
});
