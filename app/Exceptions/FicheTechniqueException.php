<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Exception métier du module fiche technique.
 *
 * Porte un code HTTP exploité par le handler global (bootstrap/app.php).
 */
class FicheTechniqueException extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 422)
    {
        parent::__construct($message);
    }

    public static function modificationFicheArchivee(int $ficheId): self
    {
        return new self("La fiche technique (id: {$ficheId}) est archivée : on ne peut pas la modifier directement.", 409);
    }

    public static function suppressionFicheArchivee(int $ficheId): self
    {
        return new self("Impossible de supprimer une fiche technique archivée (id: {$ficheId}) : l'historique doit rester intact.", 409);
    }

    public static function refereceeDansCarteActive(int $ficheId): self
    {
        return new self("Impossible de supprimer la fiche technique (id: {$ficheId}) : son produit figure dans au moins une carte active.", 409);
    }
}
