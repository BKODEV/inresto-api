<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Statut d'un inventaire.
 *
 *   en_cours ──> cloture (irréversible : génère les corrections de stock)
 *
 * Un seul inventaire `en_cours` par site à la fois (géré applicativement).
 */
enum StatutInventaire: string
{
    case EnCours = 'en_cours';
    case Cloture = 'cloture';
}
