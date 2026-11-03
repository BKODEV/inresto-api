<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\Reporting\Concerns\RequetesVente;
use Illuminate\Support\Carbon;

/**
 * Intervalle temporel résolu d'un rapport : bornes incluses [debut, fin] et
 * `type` d'origine ('jour'|'semaine'|'mois'|'plage'), qui sert notamment à
 * choisir la granularité d'une série temporelle.
 *
 * Les bornes sont toujours CALENDAIRES. Un service à cheval sur minuit ne se
 * règle pas ici : il se règle en datant la vente par l'ouverture de sa session
 * de caisse, ce qui ne demande aucun décalage d'horloge. Voir
 * {@see RequetesVente}.
 */
final class Periode
{
    public function __construct(
        public readonly Carbon $debut,
        public readonly Carbon $fin,
        public readonly string $type,
    ) {}
}
