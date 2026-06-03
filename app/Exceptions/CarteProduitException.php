<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Exception métier du pivot carte-produit.
 *
 * Porte un code HTTP exploité par le handler global (bootstrap/app.php).
 */
class CarteProduitException extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 422)
    {
        parent::__construct($message);
    }

    public static function suppressionSurCarteActive(int $carteId): self
    {
        return new self("Impossible de retirer un produit d'une carte active (id: {$carteId}) : désactivez la ligne (statut = inactif) à la place.", 409);
    }
}
