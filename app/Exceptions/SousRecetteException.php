<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Exception métier du module sous-recette.
 *
 * Porte un code HTTP exploité par le handler global (bootstrap/app.php).
 */
class SousRecetteException extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 422)
    {
        parent::__construct($message);
    }

    public static function utiliseeDansFicheTechnique(int $sousRecetteId): self
    {
        return new self(
            "Impossible de supprimer la sous-recette (id: {$sousRecetteId}) : elle est utilisée dans au moins une fiche technique.",
            409,
        );
    }
}
