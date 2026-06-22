<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Exception métier du module annulation.
 *
 * Porte un code HTTP exploité par le handler global (bootstrap/app.php).
 */
class AnnulationException extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 422)
    {
        parent::__construct($message);
    }

    public static function ligneNonAnnulable(int $commandeId): self
    {
        return new self("Les lignes de la commande (id: {$commandeId}) ne sont plus annulables (commande encaissée ou annulée).", 409);
    }

    public static function commandeNonAnnulable(int $commandeId): self
    {
        return new self("La commande (id: {$commandeId}) n'est annulable que tant qu'elle est ouverte ou envoyée en cuisine.", 409);
    }
}
