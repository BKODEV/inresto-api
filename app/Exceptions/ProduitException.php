<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Exception métier du module produit.
 *
 * Porte un code HTTP exploité par le handler global (bootstrap/app.php).
 */
class ProduitException extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 422)
    {
        parent::__construct($message);
    }

    public static function suppressionAvecFicheActive(int $produitId): self
    {
        return new self("Impossible de supprimer le produit (id: {$produitId}) : il est référencé dans une fiche technique active.", 409);
    }
}
