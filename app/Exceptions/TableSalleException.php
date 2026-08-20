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

    public static function archivageAvecCommandeEnCours(int $tableId): self
    {
        return new self("Impossible d'archiver la table (id: {$tableId}) : elle porte une commande en cours. Soldez ou annulez la commande d'abord.", 409);
    }

    public static function dejaArchivee(int $tableId): self
    {
        return new self("La table (id: {$tableId}) est déjà archivée.", 409);
    }

    public static function nonArchivee(int $tableId): self
    {
        return new self("La table (id: {$tableId}) n'est pas archivée.", 409);
    }
}
