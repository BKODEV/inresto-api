<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BudgetController;
use App\Http\Controllers\Api\CatalogueFournisseurController;
use App\Http\Controllers\Api\FournisseurController;
use App\Http\Controllers\Api\MatierePremiereController;
use App\Http\Controllers\Api\Parametres\CaisseController;
use App\Http\Controllers\Api\Parametres\CarteController;
use App\Http\Controllers\Api\Parametres\CategorieMatierePremireController;
use App\Http\Controllers\Api\Parametres\CategorieProduitController;
use App\Http\Controllers\Api\Parametres\ModeDePaiementController;
use App\Http\Controllers\Api\Parametres\UniteDeMesureController;
use App\Http\Controllers\Api\PointDeVenteController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    Route::post('login', [AuthController::class, 'login'])->name('auth.login');

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('me', [AuthController::class, 'me'])->name('auth.me');
        Route::post('logout', [AuthController::class, 'logout'])->name('auth.logout');
    });
});

Route::middleware('auth:sanctum')->group(function () {
    Route::apiResource('points-de-vente', PointDeVenteController::class)
        ->parameters(['points-de-vente' => 'point_de_vente']);

    Route::apiResource('fournisseurs', FournisseurController::class);

    Route::put('fournisseurs/{fournisseur}/catalogue', [CatalogueFournisseurController::class, 'sync'])
        ->name('fournisseurs.catalogue.sync');

    Route::apiResource('matieres-premieres', MatierePremiereController::class)
        ->parameters(['matieres-premieres' => 'matiere_premiere']);

    Route::apiResource('catalogue-fournisseur', CatalogueFournisseurController::class)
        ->parameters(['catalogue-fournisseur' => 'catalogue_fournisseur']);

    Route::apiResource('budgets', BudgetController::class);

    Route::prefix('parametres')->name('parametres.')->group(function () {
        Route::apiResource('categories-produit', CategorieProduitController::class)
            ->parameters(['categories-produit' => 'categorie_produit']);
        Route::apiResource('categories-matiere-premiere', CategorieMatierePremireController::class)
            ->parameters(['categories-matiere-premiere' => 'categorie_matiere_premire']);
        Route::apiResource('unites-de-mesure', UniteDeMesureController::class)
            ->parameters(['unites-de-mesure' => 'unite_de_mesure']);
        Route::apiResource('modes-de-paiement', ModeDePaiementController::class)
            ->parameters(['modes-de-paiement' => 'mode_de_paiement']);
        Route::apiResource('caisses', CaisseController::class)
            ->parameters(['caisses' => 'caisse']);
        Route::apiResource('cartes', CarteController::class);
    });
});
