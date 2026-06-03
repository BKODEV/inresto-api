<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Statut opérationnel d'une table de salle. Mis à jour par les modules
 * commandes/réservations (Lot C) ; ici, seul le changement manuel est exposé.
 */
enum StatutTable: string
{
    case Libre = 'libre';
    case Occupee = 'occupee';
    case Reservee = 'reservee';
    case ADebarrasser = 'a_debarrasser';
}
