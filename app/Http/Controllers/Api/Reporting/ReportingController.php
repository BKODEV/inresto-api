<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Reporting;

use App\Http\Controllers\Controller;
use App\Support\Periode;
use App\Support\PeriodeResolver;
use App\Support\SitePerimetre;
use Illuminate\Http\Request;

/**
 * Base des controllers reporting : résolution de la période et du périmètre de
 * sites (lecture seule). Le périmètre suit la permission `reporting.global` —
 * sans elle, l'utilisateur est restreint à ses sites (pivot `user_site`).
 */
abstract class ReportingController extends Controller
{
    public function __construct(protected PeriodeResolver $periodeResolver) {}

    /**
     * Vérifie la permission reporting de la famille. Le super-admin (qui ne porte
     * pas les permissions individuellement, cf. policies `before()`) est autorisé
     * d'office, comme partout ailleurs dans l'application.
     */
    protected function authorizeReporting(string $permission): void
    {
        $user = request()->user();

        abort_unless($user->isSuperAdmin() || $user->can($permission), 403, 'Action non autorisée.');
    }

    /**
     * @param  array<string, mixed>  $params
     */
    protected function periode(array $params): Periode
    {
        return $this->periodeResolver->resolve($params);
    }

    /**
     * Période immédiatement précédente, pour une lecture comparée.
     */
    protected function periodePrecedente(Periode $periode): Periode
    {
        return $this->periodeResolver->precedente($periode);
    }

    /** L'appelant demande-t-il la comparaison à la période précédente ? */
    protected function comparaison(Request $request): bool
    {
        return $request->boolean('comparer');
    }

    /**
     * Variation en pourcentage entre deux mesures, ou null.
     *
     * Null — et non zéro, ni l'infini — quand la période précédente vaut zéro :
     * passer de 0 à 400 000 n'est pas « +∞ % », c'est une comparaison qui n'a
     * pas de sens. L'écran affiche un tiret plutôt qu'un chiffre inventé.
     */
    protected function variation(float $courant, float $precedent): ?float
    {
        if ($precedent == 0.0) {
            return null;
        }

        return round(($courant - $precedent) / abs($precedent) * 100, 2);
    }

    /**
     * Enveloppe d'une lecture comparée : bornes de la période précédente et
     * mesures mises en regard.
     *
     * @param  array<string, array{courant: float, precedent: float}>  $mesures
     * @return array<string, mixed>
     */
    protected function blocComparaison(Periode $precedente, array $mesures): array
    {
        $bloc = [
            'periode_precedente' => [
                'debut' => $precedente->debut->toIso8601String(),
                'fin' => $precedente->fin->toIso8601String(),
            ],
        ];

        foreach ($mesures as $cle => $mesure) {
            $bloc[$cle] = [
                'courant' => round($mesure['courant'], 2),
                'precedent' => round($mesure['precedent'], 2),
                'variation_pct' => $this->variation($mesure['courant'], $mesure['precedent']),
            ];
        }

        return $bloc;
    }

    /**
     * Périmètre de sites à appliquer aux agrégations.
     *
     * - `null` : tous les sites (réservé à `reporting.global`, sans filtre site_id).
     * - tableau d'ids : un site explicite, ou la liste des sites de l'utilisateur.
     *
     * Un site_id hors du périmètre d'un utilisateur non-global est refusé (403).
     *
     * @return int[]|null
     */
    protected function siteScope(Request $request): ?array
    {
        $user = $request->user();

        return SitePerimetre::resoudre(
            $user,
            $request->filled('site_id') ? $request->integer('site_id') : null,
            elargi: $user->can('reporting.global'),
        );
    }

    protected function pointDeVenteId(Request $request): ?int
    {
        return $request->filled('point_de_vente_id') ? $request->integer('point_de_vente_id') : null;
    }
}
