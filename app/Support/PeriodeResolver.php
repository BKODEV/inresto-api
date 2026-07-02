<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * Résolution centralisée du filtre temporel commun à TOUS les rapports.
 *
 * Paramètres acceptés (déjà validés en amont par une FormRequest) :
 *   - periode : 'jour'|'semaine'|'mois'|'plage' (défaut 'mois')
 *   - date    : date de référence pour jour/semaine/mois (défaut aujourd'hui)
 *   - date_debut + date_fin : requis seulement si periode = 'plage'
 *
 * Conventions : 'semaine' = semaine ISO (lundi → dimanche), 'mois' = 1er au
 * dernier jour du mois. Les bornes sont toujours élargies au jour entier
 * (startOfDay / endOfDay) pour englober toutes les heures.
 */
class PeriodeResolver
{
    /**
     * @param  array<string, mixed>  $params
     */
    public function resolve(array $params): Periode
    {
        $type = $params['periode'] ?? 'mois';

        if ($type === 'plage') {
            return new Periode(
                Carbon::parse($params['date_debut'])->startOfDay(),
                Carbon::parse($params['date_fin'])->endOfDay(),
                'plage',
            );
        }

        $reference = isset($params['date']) ? Carbon::parse($params['date']) : Carbon::today();

        return match ($type) {
            'jour' => new Periode(
                $reference->copy()->startOfDay(),
                $reference->copy()->endOfDay(),
                'jour',
            ),
            'semaine' => new Periode(
                $reference->copy()->startOfWeek(Carbon::MONDAY),
                $reference->copy()->endOfWeek(Carbon::SUNDAY),
                'semaine',
            ),
            default => new Periode(
                $reference->copy()->startOfMonth(),
                $reference->copy()->endOfMonth(),
                'mois',
            ),
        };
    }
}
