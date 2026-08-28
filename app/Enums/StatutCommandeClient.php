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

    /**
     * Statuts non terminaux : la commande vit encore, elle n'est ni encaissée
     * ni annulée. `Servie` en fait partie — le couvert est servi mais l'argent
     * n'est pas encore encaissé.
     *
     * À ne pas confondre avec le contrôle de `CommandeClientService::ouvrir()`,
     * qui n'interdit une seconde commande que jusqu'à `envoyee_en_cuisine` :
     * là il s'agit d'occupation de table, ici de commande vivante.
     *
     * @return array<int, string>
     */
    public static function valeursEnCours(): array
    {
        return [
            self::Ouverte->value,
            self::EnvoyeeEnCuisine->value,
            self::Servie->value,
        ];
    }
}
