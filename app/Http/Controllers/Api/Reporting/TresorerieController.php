<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Reporting;

use App\Helpers\ApiResponse;
use App\Http\Requests\Reporting\ReportingRequest;
use App\Services\Reporting\TresorerieReportService;
use App\Support\PeriodeResolver;
use Illuminate\Http\JsonResponse;

/**
 * Où en est l'argent : position des tiroirs, et — à venir — écarts cumulés,
 * impayés, coffre et soldes fournisseurs.
 *
 * Famille de permission distincte (`reporting.tresorerie`) parce qu'elle ne
 * parle pas de marge mais d'encaisse : savoir ce qu'on a vendu et savoir ce
 * qu'on détient ne relèvent pas du même niveau de confiance.
 */
class TresorerieController extends ReportingController
{
    public function __construct(PeriodeResolver $periodeResolver, private readonly TresorerieReportService $service)
    {
        parent::__construct($periodeResolver);
    }

    /**
     * Photo instantanée : pas de filtre temporel.
     *
     * « Combien avons-nous, là, maintenant » ne se demande pas sur une période.
     */
    public function position(ReportingRequest $request): JsonResponse
    {
        $this->authorizeReporting('reporting.tresorerie');

        $data = $this->service->position($this->siteScope($request));

        return ApiResponse::success($data, 'Position de trésorerie récupérée.');
    }
}
