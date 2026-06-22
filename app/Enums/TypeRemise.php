<?php

declare(strict_types=1);

namespace App\Enums;

enum TypeRemise: string
{
    case Pourcentage = 'pourcentage';
    case MontantFixe = 'montant_fixe';
}
