<?php

declare(strict_types=1);

namespace App\Enums;

enum PointDeVenteType: string
{
    case Lounge     = 'Lounge';
    case Restaurant = 'Restaurant';
}
