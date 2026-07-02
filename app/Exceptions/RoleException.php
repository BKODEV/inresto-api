<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Exception métier du module Rôles : rôle système non supprimable, rôle encore
 * porté par des utilisateurs, niveau supérieur à celui du créateur.
 *
 * Porte un code HTTP exploité par le handler global (bootstrap/app.php).
 */
class RoleException extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 422)
    {
        parent::__construct($message);
    }

    public static function systemeNonSupprimable(): self
    {
        return new self(
            "Ce rôle est livré avec l'application (rôle système) et ne peut pas être supprimé.",
            409,
        );
    }

    public static function roleUtilise(int $nbUsers): self
    {
        return new self(
            "Ce rôle est encore attribué à {$nbUsers} utilisateur(s) : réassignez-les avant de le supprimer.",
            409,
        );
    }

    public static function niveauSuperieurAuCreateur(): self
    {
        return new self(
            "Vous ne pouvez créer ou modifier un rôle que d'un niveau strictement inférieur au vôtre.",
            403,
        );
    }
}
