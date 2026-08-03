<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Application front
    |--------------------------------------------------------------------------
    |
    | URL de base du front Angular, utilisée pour construire les liens envoyés
    | par email (réinitialisation de mot de passe). Chaque déploiement la
    | configure via l'env ; à défaut on retombe sur APP_URL.
    */
    'frontend_url' => env('FRONTEND_URL', env('APP_URL', 'http://localhost')),

    /*
    |--------------------------------------------------------------------------
    | Menu public (QR code)
    |--------------------------------------------------------------------------
    |
    | URL de base servant à construire le lien public du menu encodé dans les
    | QR codes des tables (`{base}/menu/{public_token}`). Chaque instance a son
    | propre domaine : rien n'est codé en dur. À défaut d'une valeur dédiée on
    | retombe sur le front, la page menu étant servie par la même application.
    */
    'public_menu_base_url' => env('PUBLIC_MENU_BASE_URL', env('FRONTEND_URL', env('APP_URL', 'http://localhost'))),

    /*
    |--------------------------------------------------------------------------
    | Gestion du stock
    |--------------------------------------------------------------------------
    */
    'stock' => [
        /*
         * Autorise le stock courant à devenir négatif lors d'une sortie.
         *
         * Quel que soit ce réglage, une sortie de type vente n'est JAMAIS
         * bloquée (on ne refuse jamais une vente en plein service). Ce flag
         * ne concerne que les autres sorties (perte, casse, offert, repas
         * personnel).
         */
        'autoriser_stock_negatif' => (bool) env('INRESTO_AUTORISER_STOCK_NEGATIF', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Ventes & remises
    |--------------------------------------------------------------------------
    */

    /*
     * Seuil (en %) au-delà duquel une remise nécessite la permission
     * `remise.autoriser_au_dela_seuil`. Le pourcentage effectif d'une remise
     * (pourcentage direct, ou montant fixe rapporté au total des lignes) est
     * comparé à ce seuil.
     */
    'remise_seuil_manager' => (float) env('REMISE_SEUIL_MANAGER', 15.0),

];
