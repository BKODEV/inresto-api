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

        $sites = $this->siteScope($request);
        $periode = $this->periode($request->validated());

        $pdvId = $this->pointDeVenteId($request);
        $data = $this->service->caParPointDeVente($periode, $sites, $pdvId);

        $reponse = ['points_de_vente' => $data];

        if ($this->comparaison($request)) {
            $precedente = $this->periodePrecedente($periode);
            $avant = $this->service->caParPointDeVente($precedente, $sites, $pdvId);

            $reponse['comparaison'] = $this->blocComparaison($precedente, [
                'ca' => [
                    'courant' => (float) array_sum(array_column($data, 'ca')),
                    'precedent' => (float) array_sum(array_column($avant, 'ca')),
                ],
                'nombre_commandes' => [
                    'courant' => (float) array_sum(array_column($data, 'nombre_commandes')),
                    'precedent' => (float) array_sum(array_column($avant, 'nombre_commandes')),
                ],
            ]);
        }

        return ApiResponse::success($reponse, 'CA par point de vente récupéré.');
    }

    public function produits(ReportingRequest $request): JsonResponse
    {
        $this->authorizeReporting('reporting.ventes');

        $sites = $this->siteScope($request);
        $periode = $this->periode($request->validated());

        $data = $this->service->produits(
            $periode,
            $sites,
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

        $sites = $this->siteScope($request);
        $periode = $this->periode($request->validated());

        $data = $this->service->modesPaiement(
            $periode,
            $sites,
            $this->pointDeVenteId($request),
        );

        return ApiResponse::success(['modes_paiement' => $data], 'CA par mode de paiement (encaissé) récupéré.');
    }

    public function serveurs(ReportingRequest $request): JsonResponse
    {
        $this->authorizeReporting('reporting.ventes');

        $sites = $this->siteScope($request);
        $periode = $this->periode($request->validated());

        $data = $this->service->serveurs(
            $periode,
            $sites,
            $this->pointDeVenteId($request),
        );

        return ApiResponse::success(['serveurs' => $data], 'CA par serveur récupéré.');
    }

    public function evolution(ReportingRequest $request): JsonResponse
    {
        $this->authorizeReporting('reporting.ventes');

        $sites = $this->siteScope($request);
        $periode = $this->periode($request->validated());

        $data = $this->service->evolution(
            $periode,
            $sites,
            $this->pointDeVenteId($request),
        );

        return ApiResponse::success(['serie' => $data], 'Évolution des ventes récupérée.');
    }
}
