<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Exception métier du module inventaire.
 *
 * Porte un code HTTP exploité par le handler global (bootstrap/app.php).
 */
class InventaireException extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 422)
    {
        parent::__construct($message);
    }

    public static function dejaEnCoursPourLeSite(int $siteId): self
    {
        return new self("Un inventaire est déjà en cours pour le site (id: {$siteId}).", 409);
    }

    public static function dejaCloture(int $inventaireId): self
    {
        return new self("L'inventaire (id: {$inventaireId}) est déjà clôturé.", 409);
    }

    public static function clotureSansLigne(int $inventaireId): self
    {
        return new self("Impossible de clôturer l'inventaire (id: {$inventaireId}) sans aucune ligne saisie.");
    }

    public static function ligneImmuable(int $inventaireId): self
    {
        return new self("Les lignes de l'inventaire (id: {$inventaireId}) sont en lecture seule (inventaire clôturé).", 409);
    }

    public static function suppressionAvecLignes(int $inventaireId): self
    {
        return new self("Impossible de supprimer l'inventaire (id: {$inventaireId}) : il contient des lignes ou est clôturé.", 409);
    }
}
