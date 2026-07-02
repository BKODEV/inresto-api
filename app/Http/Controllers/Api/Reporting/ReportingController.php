<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Reporting;

use App\Http\Controllers\Controller;
use App\Support\Periode;
use App\Support\PeriodeResolver;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

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
        $estGlobal = $user->isSuperAdmin() || $user->can('reporting.global');

        /** @var int[] $sitesUtilisateur */
        $sitesUtilisateur = $user->sites()->pluck('sites.id')->all();

        if ($request->filled('site_id')) {
            $siteId = $request->integer('site_id');

            if (! $estGlobal && ! in_array($siteId, $sitesUtilisateur, true)) {
                throw new AccessDeniedHttpException('Site hors de votre périmètre.');
            }

            return [$siteId];
        }

        return $estGlobal ? null : $sitesUtilisateur;
    }

    protected function pointDeVenteId(Request $request): ?int
    {
        return $request->filled('point_de_vente_id') ? $request->integer('point_de_vente_id') : null;
    }
}
