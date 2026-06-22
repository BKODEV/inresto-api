<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Exception métier du module paiement.
 *
 * Porte un code HTTP exploité par le handler global (bootstrap/app.php).
 */
class PaiementException extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 422)
    {
        parent::__construct($message);
    }

    public static function commandeImmuable(int $commandeId): self
    {
        return new self("La commande (id: {$commandeId}) est encaissée : aucun paiement ne peut plus être ajouté.", 409);
    }

    public static function commandeNonServie(int $commandeId): self
    {
        return new self("Un paiement n'est possible que sur une commande servie (id: {$commandeId}).");
    }
}
