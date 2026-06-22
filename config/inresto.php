<?php

return [

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
