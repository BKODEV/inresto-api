<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Conflits du cycle de vie d'un service de cuisine. Rendus en 409 : ce ne sont
 * pas des saisies invalides mais des états incompatibles.
 */
class SessionCuisineException extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 409)
    {
        parent::__construct($message);
    }

    public static function dejaOuverte(int $pointDeVenteId): self
    {
        return new self(
            "Un service de cuisine est déjà ouvert sur ce point de vente (#{$pointDeVenteId}). Fermez-le avant d'en ouvrir un autre."
        );
    }

    public static function dejaFermee(int $sessionId): self
    {
        return new self("Le service de cuisine #{$sessionId} est déjà fermé.");
    }

    /**
     * Fermer avec des plats encore en production laisserait la file du KDS
     * rattachée à un service clos : personne ne saurait plus qui doit les finir.
     */
    public static function lignesEnCours(int $sessionId, int $nombre): self
    {
        return new self(
            "Le service de cuisine #{$sessionId} porte encore {$nombre} plat(s) non annoncés au pass. Terminez-les ou annulez-les avant de fermer."
        );
    }
}
