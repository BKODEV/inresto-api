<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Reporting;

use App\Helpers\ApiResponse;
use App\Http\Requests\Reporting\ReportingRequest;
use App\Services\Reporting\StockReportService;
use App\Support\PeriodeResolver;
use Illuminate\Http\JsonResponse;

class StockController extends ReportingController
{
    public function __construct(PeriodeResolver $periodeResolver, private readonly StockReportService $service)
    {
        parent::__construct($periodeResolver);
    }

    /**
     * Photo instantanée : pas de filtre temporel.
     */
    public function valorisation(ReportingRequest $request): JsonResponse
    {
        $this->authorizeReporting('reporting.stock');

        $data = $this->service->valorisation($this->siteScope($request));

        return ApiResponse::success($data, 'Valorisation du stock récupérée.');
    }

    public function consommation(ReportingRequest $request): JsonResponse
    {
        $this->authorizeReporting('reporting.stock');

        $data = $this->service->consommation(
            $this->periode($request->validated()),
            $this->siteScope($request),
        );

        return ApiResponse::success(['matieres' => $data], 'Consommation matière récupérée.');
    }

    public function ecartsInventaire(ReportingRequest $request): JsonResponse
    {
        $this->authorizeReporting('reporting.stock');

        $data = $this->service->ecartsInventaire(
            $this->periode($request->validated()),
            $this->siteScope($request),
            $request->boolean('detail'),
        );

        return ApiResponse::success(['inventaires' => $data], 'Écarts d\'inventaire récupérés.');
    }

    /**
     * Photo instantanée : pas de filtre temporel.
     */
    public function alertes(ReportingRequest $request): JsonResponse
    {
        $this->authorizeReporting('reporting.stock');

        $data = $this->service->alertes($this->siteScope($request));

        return ApiResponse::success(['alertes' => $data], 'Alertes de stock récupérées.');
    }
}
