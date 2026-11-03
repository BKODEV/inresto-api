<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\Reporting\Concerns\RequetesVente;
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
 *
 * Le calendrier fait foi, sans exception ni heure de bascule. Un service qui
 * finit à 2h du matin ne se rattrape pas en décalant les bornes — il se rattrape
 * en datant la vente par l'OUVERTURE de sa session de caisse
 * ({@see RequetesVente}). La session est le
 * service ; l'heure de bascule n'en était qu'une approximation, à régler site
 * par site, et qui contaminait au passage les colonnes de type `date`.
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

    /**
     * Période immédiatement précédente, de même nature et de même durée.
     *
     * Sert la comparaison « et le mois d'avant ? », que le pilotage ne savait pas
     * poser : on ne pouvait que constater, jamais comparer.
     */
    public function precedente(Periode $periode): Periode
    {
        [$debut, $fin] = match ($periode->type) {
            'jour' => [$periode->debut->copy()->subDay(), $periode->fin->copy()->subDay()],
            'semaine' => [$periode->debut->copy()->subWeek(), $periode->fin->copy()->subWeek()],
            // Le mois se recalcule depuis son début : reculer les DEUX bornes
            // d'un mois donnerait un 30 mai pour un mois de mai, les mois n'ayant
            // pas la même longueur. La fin est la veille du début du mois suivant.
            'mois' => [
                $debutMois = $periode->debut->copy()->subMonthNoOverflow(),
                $debutMois->copy()->addMonthNoOverflow()->subSecond(),
            ],
            // Une plage n'a pas de période « d'avant » naturelle : on recule
            // d'autant de jours qu'elle en compte, bornes incluses.
            default => $this->plagePrecedente($periode),
        };

        return new Periode($debut, $fin, $periode->type);
    }

    /**
     * Plage de même longueur, collée juste avant celle reçue.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function plagePrecedente(Periode $periode): array
    {
        $jours = $periode->debut->copy()->startOfDay()->diffInDays($periode->fin->copy()->startOfDay()) + 1;

        return [
            $periode->debut->copy()->subDays($jours),
            $periode->fin->copy()->subDays($jours),
        ];
    }
}
