<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Un service de cuisine est ouvert ou clos. Pas d'état intermédiaire : la
 * brigade est là ou elle ne l'est pas.
 */
enum StatutSessionCuisine: string
{
    case Ouverte = 'ouverte';
    case Fermee = 'fermee';
}
