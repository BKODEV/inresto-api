<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Reporting;

use App\Helpers\ApiResponse;
use App\Http\Requests\Reporting\ReportingRequest;
use App\Services\Reporting\DashboardReportService;
use App\Support\PeriodeResolver;
use Illuminate\Http\JsonResponse;

/**
 * Tableau de bord de tête (lecture seule) : une seule réponse agrégée qui compose
 * les rapports food cost / ventes / stock / budget, pour éviter une rafale
 * d'appels au chargement du front.
 *
 * Permission dédiée `reporting.dashboard` : une seule habilitation ouvre la vue
 * de synthèse transversale, indépendamment des permissions par famille. Le
 * périmètre de sites reste identique aux autres rapports (`reporting.global`
 * pour tous les sites, sinon les sites de l'utilisateur).
 */
class DashboardController extends ReportingController
{
    public function __construct(PeriodeResolver $periodeResolver, private readonly DashboardReportService $service)
    {
        parent::__construct($periodeResolver);
    }

    public function index(ReportingRequest $request): JsonResponse
    {
        $this->authorizeReporting('reporting.dashboard');

        $data = $this->service->apercu($this->siteScope($request));

        return ApiResponse::success($data, 'Tableau de bord récupéré.');
    }
}
