<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Exception métier des versements de caisse.
 *
 * Porte un code HTTP exploité par le handler global (bootstrap/app.php).
 */
class VersementException extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 422)
    {
        parent::__construct($message);
    }

    public static function caisseJamaisOuverte(int $caisseId): self
    {
        return new self(
            "La caisse (id: {$caisseId}) n'a jamais été ouverte : aucun tiroir d'où verser.",
            409
        );
    }
}
