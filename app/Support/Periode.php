<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * Intervalle temporel résolu d'un rapport : bornes incluses [debut, fin] et
 * `type` d'origine ('jour'|'semaine'|'mois'|'plage'), qui sert notamment à
 * choisir la granularité d'une série temporelle.
 */
final class Periode
{
    public function __construct(
        public readonly Carbon $debut,
        public readonly Carbon $fin,
        public readonly string $type,
    ) {}
}
