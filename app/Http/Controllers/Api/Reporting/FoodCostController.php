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

        $sites = $this->siteScope($request);
        $periode = $this->periode($request->validated());

        $data = $this->service->parProduit(
            $periode,
            $sites,
            $this->pointDeVenteId($request),
        );

        return ApiResponse::success($data, 'Food cost par produit récupéré.');
    }

    public function pointsDeVente(ReportingRequest $request): JsonResponse
    {
        $this->authorizeReporting('reporting.food_cost');

        $sites = $this->siteScope($request);
        $periode = $this->periode($request->validated());

        $data = $this->service->parPointDeVente(
            $periode,
            $sites,
            $this->pointDeVenteId($request),
        );

        return ApiResponse::success($data, 'Food cost par point de vente récupéré.');
    }

    public function synthese(ReportingRequest $request): JsonResponse
    {
        $this->authorizeReporting('reporting.food_cost');

        $sites = $this->siteScope($request);
        $periode = $this->periode($request->validated());

        $pdvId = $this->pointDeVenteId($request);
        $data = $this->service->synthese($periode, $sites, $pdvId);

        $reponse = [...$data];

        if ($this->comparaison($request)) {
            $precedente = $this->periodePrecedente($periode);
            $avant = $this->service->synthese($precedente, $sites, $pdvId);

            // On compare le food cost RÉEL et la marge : le théorique ne bouge
            // qu'avec les fiches techniques, une variation d'un mois sur l'autre
            // y dirait qu'on a re-fiché des recettes, pas qu'on a mieux produit.
            $reponse['comparaison'] = $this->blocComparaison($precedente, [
                'food_cost_reel_pct' => [
                    'courant' => (float) $data['food_cost_reel_pct'],
                    'precedent' => (float) $avant['food_cost_reel_pct'],
                ],
                'marge_brute_totale' => [
                    'courant' => (float) $data['marge_brute_totale'],
                    'precedent' => (float) $avant['marge_brute_totale'],
                ],
            ]);
        }

        return ApiResponse::success($reponse, 'Synthèse food cost récupérée.');
    }
}
