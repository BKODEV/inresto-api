<?php

declare(strict_types=1);

namespace App\Enums;

enum CarteStatut: string
{
    case Brouillon = 'brouillon';
    case Active    = 'active';
    case Archivee  = 'archivee';
}
