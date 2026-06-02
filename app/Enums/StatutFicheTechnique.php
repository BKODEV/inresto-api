<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Statut d'une fiche technique.
 *
 * Une seule fiche `active` par produit (géré applicativement) ; les versions
 * précédentes passent en `archivee` et restent figées (historique).
 */
enum StatutFicheTechnique: string
{
    case Active = 'active';
    case Archivee = 'archivee';
}
