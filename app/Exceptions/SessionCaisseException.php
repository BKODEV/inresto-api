<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Exception métier du module sessions de caisse.
 *
 * Porte un code HTTP exploité par le handler global (bootstrap/app.php).
 */
class SessionCaisseException extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 422)
    {
        parent::__construct($message);
    }

    public static function dejaOuvertePourLaCaisse(int $caisseId): self
    {
        return new self("Une session est déjà ouverte pour la caisse (id: {$caisseId}).", 409);
    }

    public static function dejaFermee(int $sessionId): self
    {
        return new self("La session de caisse (id: {$sessionId}) est déjà fermée.", 409);
    }
}
