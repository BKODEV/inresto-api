<?php

use App\Http\Controllers\Api\AnnulationController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BudgetController;
use App\Http\Controllers\Api\CarteProduitController;
use App\Http\Controllers\Api\CatalogueFournisseurController;
use App\Http\Controllers\Api\CommandeClientController;
use App\Http\Controllers\Api\CommandeFournisseurController;
use App\Http\Controllers\Api\DepenseController;
use App\Http\Controllers\Api\FicheTechniqueController;
use App\Http\Controllers\Api\FournisseurController;
use App\Http\Controllers\Api\InventaireController;
use App\Http\Controllers\Api\JournalController;
use App\Http\Controllers\Api\LigneCommandeFournisseurController;
use App\Http\Controllers\Api\MatierePremiereController;
use App\Http\Controllers\Api\MouvementStockController;
use App\Http\Controllers\Api\PaiementController;
use App\Http\Controllers\Api\ParametreEtablissementController;
use App\Http\Controllers\Api\Parametres\CaisseController;
use App\Http\Controllers\Api\Parametres\CarteController;
use App\Http\Controllers\Api\Parametres\CategorieMatierePremireController;
use App\Http\Controllers\Api\Parametres\CategorieProduitController;
use App\Http\Controllers\Api\Parametres\ModeDePaiementController;
use App\Http\Controllers\Api\Parametres\PosteCuisineController;
use App\Http\Controllers\Api\Parametres\UniteDeMesureController;
use App\Http\Controllers\Api\PasswordResetController;
use App\Http\Controllers\Api\PdfController;
use App\Http\Controllers\Api\PermissionController;
use App\Http\Controllers\Api\PointDeVenteController;
use App\Http\Controllers\Api\PourboireController;
use App\Http\Controllers\Api\ProduitController;
use App\Http\Controllers\Api\PublicMenuController;
use App\Http\Controllers\Api\ReceptionController;
use App\Http\Controllers\Api\RemiseController;
use App\Http\Controllers\Api\Reporting\BudgetController as ReportingBudgetController;
use App\Http\Controllers\Api\Reporting\DashboardController as ReportingDashboardController;
use App\Http\Controllers\Api\Reporting\FoodCostController as ReportingFoodCostController;
use App\Http\Controllers\Api\Reporting\StockController as ReportingStockController;
use App\Http\Controllers\Api\Reporting\VentesController as ReportingVentesController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\SessionCaisseController;
use App\Http\Controllers\Api\SiteController;
use App\Http\Controllers\Api\SousRecetteController;
use App\Http\Controllers\Api\StockController;
use App\Http\Controllers\Api\TableSalleController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\VersementController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    Route::post('login', [AuthController::class, 'login'])->name('auth.login');

    // Route publique : consommation du token de réinitialisation reçu par email.
    Route::post('reset-password', [PasswordResetController::class, 'reset'])->name('auth.reset-password');

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('me', [AuthController::class, 'me'])->name('auth.me');
        Route::post('logout', [AuthController::class, 'logout'])->name('auth.logout');
    });
});

/*
 * Menu public par QR code — SEUL périmètre non authentifié de l'API (avec le
 * login et la consommation d'un lien de réinitialisation). Le jeton de la table
 * fait office de clé d'accès ; le throttle limite le balayage de jetons et
 * l'abus d'un lien qui circule librement.
 */
Route::prefix('public')->middleware('throttle:60,1')->group(function () {
    Route::get('menu/{public_token}', [PublicMenuController::class, 'show'])->name('public.menu.show');
});

Route::middleware('auth:sanctum')->group(function () {
    // Module Utilisateurs & Rôles (administration).
    Route::post('users/{user}/activer', [UserController::class, 'activer'])->name('users.activer');
    Route::post('users/{user}/desactiver', [UserController::class, 'desactiver'])->name('users.desactiver');
    Route::post('users/{user}/reset-password', [UserController::class, 'resetPassword'])->name('users.reset-password');
    Route::post('users/{user}/send-reset-link', [UserController::class, 'sendResetLink'])->name('users.send-reset-link');
    Route::apiResource('users', UserController::class);

    Route::apiResource('roles', RoleController::class);
    Route::get('permissions', [PermissionController::class, 'index'])->name('permissions.index');

    // Périmètre métier de l'utilisateur connecté (sélecteur de site).
    // Déclaré avant la ressource pour ne pas être capté par sites/{site}.
    Route::get('sites/perimetre', [SiteController::class, 'perimetre'])->name('sites.perimetre');

    // Paramètres d'établissement par site (une fiche/site) + logo.
    Route::get('sites/{site}/parametres', [ParametreEtablissementController::class, 'show'])
        ->name('sites.parametres.show');
    Route::put('sites/{site}/parametres', [ParametreEtablissementController::class, 'store'])
        ->name('sites.parametres.store');
    Route::post('sites/{site}/parametres/logo', [ParametreEtablissementController::class, 'uploadLogo'])
        ->name('sites.parametres.logo.upload');
    Route::delete('sites/{site}/parametres/logo', [ParametreEtablissementController::class, 'deleteLogo'])
        ->name('sites.parametres.logo.delete');

    Route::apiResource('sites', SiteController::class);

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
    Route::get('commandes-fournisseur/{commande_fournisseur}/pdf', [PdfController::class, 'bonCommandeFournisseur'])
        ->name('commandes-fournisseur.pdf');
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
    Route::get('fiches-techniques/{fiche_technique}/pdf', [PdfController::class, 'ficheTechnique'])
        ->whereNumber('fiche_technique')
        ->name('fiches-techniques.pdf');
    Route::apiResource('fiches-techniques', FicheTechniqueController::class)
        ->parameters(['fiches-techniques' => 'fiche_technique']);

    // Module Salle — Produits (définition partagée) + produits sur une carte.
    // La photo alimente le menu public : elle ne s'écrit que par téléversement,
    // jamais par le PUT du produit.
    Route::post('produits/{produit}/photo', [ProduitController::class, 'uploadPhoto'])
        ->name('produits.photo.upload');
    Route::delete('produits/{produit}/photo', [ProduitController::class, 'deletePhoto'])
        ->name('produits.photo.delete');
    Route::apiResource('produits', ProduitController::class)
        ->parameters(['produits' => 'produit']);
    // Déclaré avant la ressource : composer une carte produit par produit est
    // impraticable, ce lot ajoute toute une sélection en une requête.
    Route::post('cartes/{carte}/produits/lot', [CarteProduitController::class, 'storeLot'])
        ->name('cartes.produits.lot');
    Route::apiResource('cartes.produits', CarteProduitController::class)
        ->only(['index', 'store', 'update', 'destroy'])
        ->parameters(['cartes' => 'carte', 'produits' => 'carte_produit']);

    // Journal d'activité — lecture seule, agrège les traces des autres modules.
    Route::get('journal', [JournalController::class, 'index'])->name('journal.index');

    // Module Salle — Tables de salle (CRUD par PDV + changement de statut manuel).
    // Déclarée AVANT l'apiResource, sinon `tables/{table}` capture « lot ».
    Route::post('tables/lot', [TableSalleController::class, 'storeLot'])
        ->name('tables.lot');
    Route::apiResource('tables', TableSalleController::class)
        ->parameters(['tables' => 'table']);
    Route::patch('tables/{table}/statut', [TableSalleController::class, 'changerStatut'])
        ->name('tables.statut');
    // Retrait/remise en service : gouverné par `table.delete`, c'est la
    // contrepartie réversible de la suppression pour une table qui a servi.
    Route::post('tables/{table}/archiver', [TableSalleController::class, 'archiver'])
        ->name('tables.archiver');
    Route::post('tables/{table}/reactiver', [TableSalleController::class, 'reactiver'])
        ->name('tables.reactiver');

    // Module Salle — QR codes du menu public (côté administration).
    Route::post('tables/{table}/regenerer-token', [TableSalleController::class, 'regenererToken'])
        ->name('tables.regenerer-token');
    Route::get('tables/{table}/qr-pdf', [PdfController::class, 'qrTable'])
        ->name('tables.qr-pdf');
    Route::get('points-de-vente/{point_de_vente}/qr-planche-pdf', [PdfController::class, 'qrPlanchePointDeVente'])
        ->name('points-de-vente.qr-planche-pdf');

    // Module Salle — Sessions de caisse (ouverture/fermeture).
    Route::apiResource('sessions-caisse', SessionCaisseController::class)
        ->only(['index', 'store', 'show'])
        ->parameters(['sessions-caisse' => 'session']);
    Route::post('sessions-caisse/{session}/fermer', [SessionCaisseController::class, 'fermer'])
        ->name('sessions-caisse.fermer');
    Route::get('pourboires/soldes', [PourboireController::class, 'soldes'])
        ->name('pourboires.soldes');
    Route::get('pourboires/mon-solde', [PourboireController::class, 'monSolde'])
        ->name('pourboires.mon-solde');
    Route::get('caisses/{caisse}/versements', [VersementController::class, 'index'])
        ->name('caisses.versements.index');
    Route::post('caisses/{caisse}/versements', [VersementController::class, 'store'])
        ->name('caisses.versements.store');
    Route::get('sessions-caisse/{session}/rapport-fermeture', [PdfController::class, 'rapportFermeture'])
        ->name('sessions-caisse.rapport-fermeture');

    // Module Salle — Commandes client + KDS + déstockage à la vente.
    Route::apiResource('commandes-client', CommandeClientController::class)
        ->only(['index', 'store', 'show'])
        ->parameters(['commandes-client' => 'commande']);
    Route::get('commandes-client/{commande}/pdf', [PdfController::class, 'factureClient'])
        ->name('commandes-client.pdf');
    Route::get('commandes-client/{commande}/addition', [PdfController::class, 'additionClient'])
        ->name('commandes-client.addition');
    Route::get('commandes-client/{commande}/recu', [PdfController::class, 'recuClient'])
        ->name('commandes-client.recu');
    Route::post('commandes-client/{commande}/lignes', [CommandeClientController::class, 'ajouterLigne'])
        ->name('commandes-client.lignes.store');
    Route::post('commandes-client/{commande}/envoyer-en-cuisine', [CommandeClientController::class, 'envoyerEnCuisine'])
        ->name('commandes-client.envoyer-en-cuisine');
    // Annonce au pass : gouvernée par `commande_client.marquer_pret`, et non par
    // `update` comme le service. C'est ce qui permet un rôle cuisine qui annonce
    // sans pouvoir modifier ni servir les notes de salle.
    Route::post('commandes-client/{commande}/marquer-pret', [CommandeClientController::class, 'marquerPret'])
        ->name('commandes-client.marquer-pret');
    Route::post('commandes-client/{commande}/annuler-pret', [CommandeClientController::class, 'annulerPret'])
        ->name('commandes-client.annuler-pret');
    Route::post('commandes-client/{commande}/servir', [CommandeClientController::class, 'servir'])
        ->name('commandes-client.servir');
    Route::post('commandes-client/{commande}/encaisser', [CommandeClientController::class, 'encaisser'])
        ->name('commandes-client.encaisser');
    Route::get('kds/{point_de_vente}', [CommandeClientController::class, 'kds'])
        ->name('kds.index');
    // Le pendant salle du KDS : ce qui est prêt et attend d'être emporté.
    Route::get('pass/{point_de_vente}', [CommandeClientController::class, 'pass'])
        ->name('pass.index');

    // Module Salle — Lot C-2 : paiements, remises, annulations.
    Route::post('commandes-client/{commande}/paiements', [PaiementController::class, 'store'])
        ->name('commandes-client.paiements.store');
    Route::post('commandes-client/{commande}/remises', [RemiseController::class, 'store'])
        ->name('commandes-client.remises.store');
    // Abandon d'une commande jamais garnie : suppression sèche, sans motif.
    // L'annulation motivée reste la seule issue dès qu'elle a porté quelque chose.
    Route::delete('commandes-client/{commande}', [CommandeClientController::class, 'destroy'])
        ->name('commandes-client.destroy');
    Route::post('commandes-client/{commande}/annuler', [AnnulationController::class, 'annulerCommande'])
        ->name('commandes-client.annuler');
    Route::delete('commandes-client/{commande}/lignes/{ligne}', [AnnulationController::class, 'annulerLigne'])
        ->name('commandes-client.lignes.annuler');

    // État du stock par site + journal des mouvements (lecture seule, écran
    // opérationnel de cuisine — permission `stock.view`).
    Route::get('stock', [StockController::class, 'index'])->name('stock.index');
    Route::get('stock/mouvements', [MouvementStockController::class, 'index'])
        ->name('stock.mouvements.index');

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

    // Paramètres généraux (CRUD + listes pour selects).
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
        // Postes de production (« Entrées froides », « Chaud », « Bar »…) et
        // les catégories de carte qu'ils prennent en charge.
        Route::apiResource('postes-cuisine', PosteCuisineController::class)
            ->parameters(['postes-cuisine' => 'poste_cuisine']);
    });

    // Module Reporting — lecture seule (agrégations JSON pour le front).
    Route::prefix('reporting')->name('reporting.')->group(function () {
        // Tableau de bord de tête : une réponse agrégée transversale.
        Route::get('dashboard', [ReportingDashboardController::class, 'index'])->name('dashboard');

        Route::prefix('food-cost')->name('food-cost.')->group(function () {
            Route::get('produits', [ReportingFoodCostController::class, 'produits'])->name('produits');
            Route::get('points-de-vente', [ReportingFoodCostController::class, 'pointsDeVente'])->name('points-de-vente');
            Route::get('synthese', [ReportingFoodCostController::class, 'synthese'])->name('synthese');
        });

        Route::prefix('ventes')->name('ventes.')->group(function () {
            Route::get('points-de-vente', [ReportingVentesController::class, 'pointsDeVente'])->name('points-de-vente');
            Route::get('produits', [ReportingVentesController::class, 'produits'])->name('produits');
            Route::get('modes-paiement', [ReportingVentesController::class, 'modesPaiement'])->name('modes-paiement');
            Route::get('serveurs', [ReportingVentesController::class, 'serveurs'])->name('serveurs');
            Route::get('evolution', [ReportingVentesController::class, 'evolution'])->name('evolution');
        });

        Route::prefix('stock')->name('stock.')->group(function () {
            Route::get('valorisation', [ReportingStockController::class, 'valorisation'])->name('valorisation');
            Route::get('consommation', [ReportingStockController::class, 'consommation'])->name('consommation');
            Route::get('ecarts-inventaire', [ReportingStockController::class, 'ecartsInventaire'])->name('ecarts-inventaire');
            Route::get('alertes', [ReportingStockController::class, 'alertes'])->name('alertes');
        });

        Route::prefix('budget')->name('budget.')->group(function () {
            Route::get('consommation', [ReportingBudgetController::class, 'consommation'])->name('consommation');
            Route::get('depenses', [ReportingBudgetController::class, 'depenses'])->name('depenses');
        });
    });
});
