<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Exception métier du module remise.
 *
 * Porte un code HTTP exploité par le handler global (bootstrap/app.php).
 */
class RemiseException extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 422)
    {
        parent::__construct($message);
    }

    public static function statutInvalide(int $commandeId): self
    {
        return new self("Une remise n'est possible que sur une commande ouverte ou servie (id: {$commandeId}).", 409);
    }

    public static function seuilDepasse(float $seuil): self
    {
        return new self("Cette remise dépasse le seuil autorisé ({$seuil} %) : permission requise.", 403);
    }
}
