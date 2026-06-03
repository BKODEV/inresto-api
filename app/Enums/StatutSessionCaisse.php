<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Statut d'une session de caisse.
 *
 *   ouverte ──> fermee (irréversible : fige montant_theorique, montant_reel, ecart)
 *
 * Une seule session `ouverte` par caisse à la fois (index partiel + verrou applicatif).
 */
enum StatutSessionCaisse: string
{
    case Ouverte = 'ouverte';
    case Fermee = 'fermee';
}
