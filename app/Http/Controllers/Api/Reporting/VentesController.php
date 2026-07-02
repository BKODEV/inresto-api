<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Reporting;

use App\Helpers\ApiResponse;
use App\Http\Requests\Reporting\ReportingRequest;
use App\Services\Reporting\VentesReportService;
use App\Support\PeriodeResolver;
use Illuminate\Http\JsonResponse;

class VentesController extends ReportingController
{
    public function __construct(PeriodeResolver $periodeResolver, private readonly VentesReportService $service)
    {
        parent::__construct($periodeResolver);
    }

    public function pointsDeVente(ReportingRequest $request): JsonResponse
    {
        $this->authorizeReporting('reporting.ventes');

        $data = $this->service->caParPointDeVente(
            $this->periode($request->validated()),
            $this->siteScope($request),
            $this->pointDeVenteId($request),
        );

        return ApiResponse::success(['points_de_vente' => $data], 'CA par point de vente récupéré.');
    }

    public function produits(ReportingRequest $request): JsonResponse
    {
        $this->authorizeReporting('reporting.ventes');

        $data = $this->service->produits(
            $this->periode($request->validated()),
            $this->siteScope($request),
            $this->pointDeVenteId($request),
            $request->input('tri', 'ca'),
            $request->input('ordre', 'desc'),
            $request->integer('limit', 20),
        );

        return ApiResponse::success(['produits' => $data], 'Top/flop produits récupéré.');
    }

    public function modesPaiement(ReportingRequest $request): JsonResponse
    {
        $this->authorizeReporting('reporting.ventes');

        $data = $this->service->modesPaiement(
            $this->periode($request->validated()),
            $this->siteScope($request),
            $this->pointDeVenteId($request),
        );

        return ApiResponse::success(['modes_paiement' => $data], 'CA par mode de paiement (encaissé) récupéré.');
    }

    public function serveurs(ReportingRequest $request): JsonResponse
    {
        $this->authorizeReporting('reporting.ventes');

        $data = $this->service->serveurs(
            $this->periode($request->validated()),
            $this->siteScope($request),
            $this->pointDeVenteId($request),
        );

        return ApiResponse::success(['serveurs' => $data], 'CA par serveur récupéré.');
    }

    public function evolution(ReportingRequest $request): JsonResponse
    {
        $this->authorizeReporting('reporting.ventes');

        $data = $this->service->evolution(
            $this->periode($request->validated()),
            $this->siteScope($request),
            $this->pointDeVenteId($request),
        );

        return ApiResponse::success(['serie' => $data], 'Évolution des ventes récupérée.');
    }
}
