<?php

declare(strict_types=1);

namespace App\Enums;

enum Statut: string
{
    case Actif   = 'actif';
    case Inactif = 'inactif';
}
