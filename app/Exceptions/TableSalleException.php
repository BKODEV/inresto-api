<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Exception métier du module tables de salle.
 *
 * Porte un code HTTP exploité par le handler global (bootstrap/app.php).
 */
class TableSalleException extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 422)
    {
        parent::__construct($message);
    }

    public static function suppressionAvecCommandes(int $tableId): self
    {
        return new self("Impossible de supprimer la table (id: {$tableId}) : elle porte des commandes client.", 409);
    }
}
