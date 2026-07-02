<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Reporting;

use App\Helpers\ApiResponse;
use App\Http\Requests\Reporting\ReportingRequest;
use App\Services\Reporting\FoodCostReportService;
use App\Support\PeriodeResolver;
use Illuminate\Http\JsonResponse;

class FoodCostController extends ReportingController
{
    public function __construct(PeriodeResolver $periodeResolver, private readonly FoodCostReportService $service)
    {
        parent::__construct($periodeResolver);
    }

    public function produits(ReportingRequest $request): JsonResponse
    {
        $this->authorizeReporting('reporting.food_cost');

        $data = $this->service->parProduit(
            $this->periode($request->validated()),
            $this->siteScope($request),
            $this->pointDeVenteId($request),
        );

        return ApiResponse::success($data, 'Food cost par produit récupéré.');
    }

    public function pointsDeVente(ReportingRequest $request): JsonResponse
    {
        $this->authorizeReporting('reporting.food_cost');

        $data = $this->service->parPointDeVente(
            $this->periode($request->validated()),
            $this->siteScope($request),
            $this->pointDeVenteId($request),
        );

        return ApiResponse::success($data, 'Food cost par point de vente récupéré.');
    }

    public function synthese(ReportingRequest $request): JsonResponse
    {
        $this->authorizeReporting('reporting.food_cost');

        $data = $this->service->synthese(
            $this->periode($request->validated()),
            $this->siteScope($request),
            $this->pointDeVenteId($request),
        );

        return ApiResponse::success($data, 'Synthèse food cost récupérée.');
    }
}
