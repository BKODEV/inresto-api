<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Statut d'une commande client (salle).
 *
 *   ouverte ──> envoyee_en_cuisine ──> servie ──> encaissee
 *      │                  │
 *      └──> annulee       └──> annulee
 *
 * Le déstockage (StockService::sortie) a lieu au passage `servie`.
 */
enum StatutCommandeClient: string
{
    case Ouverte = 'ouverte';
    case EnvoyeeEnCuisine = 'envoyee_en_cuisine';
    case Servie = 'servie';
    case Encaissee = 'encaissee';
    case Annulee = 'annulee';
}
