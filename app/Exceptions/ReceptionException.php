<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Enums\StatutCommandeFournisseur;
use RuntimeException;

/**
 * Exception métier du module réception (budget de site absent, facteur de
 * conversion manquant au catalogue, statut de commande incompatible,
 * réception déjà confirmée).
 *
 * Porte un code HTTP exploité par le handler global (bootstrap/app.php).
 */
class ReceptionException extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 422)
    {
        parent::__construct($message);
    }

    public static function statutCommandeInvalide(StatutCommandeFournisseur $statut): self
    {
        return new self(
            "Une réception ne peut porter que sur une commande « envoyee » ou « partiellement_recue » (statut actuel : « {$statut->value} »).",
            409,
        );
    }

    public static function facteurConversionManquant(int $fournisseurId, int $matierePremiereId): self
    {
        return new self(
            "Facteur de conversion inconnu : le catalogue du fournisseur (id: {$fournisseurId}) ne référence pas la matière première (id: {$matierePremiereId}). Renseignez le catalogue avant de réceptionner.",
        );
    }

    public static function budgetSiteAbsent(int $siteId, string $mois): self
    {
        return new self(
            "Aucun budget actif pour le site (id: {$siteId}) sur le mois {$mois}. La réception est annulée : impossible d'imputer la dépense.",
        );
    }

    public static function matiereEnDoublon(int $matierePremiereId): self
    {
        return new self(
            "L'article (id: {$matierePremiereId}) apparaît plusieurs fois dans la réception : cumulez les quantités sur une seule ligne.",
            409,
        );
    }

    public static function dejaConfirmee(int $receptionId): self
    {
        return new self("La réception (id: {$receptionId}) est déjà confirmée.", 409);
    }
}
