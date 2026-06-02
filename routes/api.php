<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BudgetController;
use App\Http\Controllers\Api\CatalogueFournisseurController;
use App\Http\Controllers\Api\CommandeFournisseurController;
use App\Http\Controllers\Api\DepenseController;
use App\Http\Controllers\Api\FicheTechniqueController;
use App\Http\Controllers\Api\FournisseurController;
use App\Http\Controllers\Api\InventaireController;
use App\Http\Controllers\Api\LigneCommandeFournisseurController;
use App\Http\Controllers\Api\MatierePremiereController;
use App\Http\Controllers\Api\Parametres\CaisseController;
use App\Http\Controllers\Api\Parametres\CarteController;
use App\Http\Controllers\Api\Parametres\CategorieMatierePremireController;
use App\Http\Controllers\Api\Parametres\CategorieProduitController;
use App\Http\Controllers\Api\Parametres\ModeDePaiementController;
use App\Http\Controllers\Api\Parametres\UniteDeMesureController;
use App\Http\Controllers\Api\PointDeVenteController;
use App\Http\Controllers\Api\ReceptionController;
use App\Http\Controllers\Api\SousRecetteController;
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
    Route::post('budgets/{budget}/depenses', [DepenseController::class, 'store'])
        ->name('budgets.depenses.store');
    Route::get('sites/{site}/budgets', [BudgetController::class, 'indexForSite'])
        ->name('sites.budgets.index');
    Route::get('sites/{site}/budgets/{mois}', [BudgetController::class, 'showForSiteMonth'])
        ->where('mois', '[0-9]{4}-[0-9]{2}')
        ->name('sites.budgets.show');

    Route::apiResource('commandes-fournisseur', CommandeFournisseurController::class)
        ->parameters(['commandes-fournisseur' => 'commande_fournisseur']);
    Route::post('commandes-fournisseur/{commande_fournisseur}/envoyer', [CommandeFournisseurController::class, 'envoyer'])
        ->name('commandes-fournisseur.envoyer');
    Route::post('commandes-fournisseur/{commande_fournisseur}/annuler', [CommandeFournisseurController::class, 'annuler'])
        ->name('commandes-fournisseur.annuler');
    Route::post('commandes-fournisseur/{commande_fournisseur}/lignes', [LigneCommandeFournisseurController::class, 'store'])
        ->name('commandes-fournisseur.lignes.store');
    Route::put('commandes-fournisseur/{commande_fournisseur}/lignes/{ligne}', [LigneCommandeFournisseurController::class, 'update'])
        ->name('commandes-fournisseur.lignes.update');
    Route::delete('commandes-fournisseur/{commande_fournisseur}/lignes/{ligne}', [LigneCommandeFournisseurController::class, 'destroy'])
        ->name('commandes-fournisseur.lignes.destroy');

    Route::apiResource('receptions', ReceptionController::class)
        ->only(['index', 'store', 'show', 'destroy']);
    Route::post('receptions/{reception}/confirmer', [ReceptionController::class, 'confirmer'])
        ->name('receptions.confirmer');

    Route::apiResource('sous-recettes', SousRecetteController::class)
        ->parameters(['sous-recettes' => 'sous_recette']);

    Route::get('fiches-techniques/{produit_id}/historique', [FicheTechniqueController::class, 'historique'])
        ->whereNumber('produit_id')
        ->name('fiches-techniques.historique');
    Route::apiResource('fiches-techniques', FicheTechniqueController::class)
        ->parameters(['fiches-techniques' => 'fiche_technique']);

    Route::apiResource('inventaires', InventaireController::class)
        ->only(['index', 'store', 'show', 'destroy']);
    Route::post('inventaires/{inventaire}/lignes', [InventaireController::class, 'lignes'])
        ->name('inventaires.lignes.store');
    Route::delete('inventaires/{inventaire}/lignes/{ligne}', [InventaireController::class, 'supprimerLigne'])
        ->name('inventaires.lignes.destroy');
    Route::post('inventaires/{inventaire}/cloturer', [InventaireController::class, 'cloturer'])
        ->name('inventaires.cloturer');
    Route::get('inventaires/{inventaire}/rapport', [InventaireController::class, 'rapport'])
        ->name('inventaires.rapport');

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
