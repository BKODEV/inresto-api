<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Reporting;

use App\Helpers\ApiResponse;
use App\Http\Requests\Reporting\ReportingRequest;
use App\Http\Resources\BudgetResource;
use App\Services\Reporting\BudgetReportService;
use App\Support\PeriodeResolver;
use Illuminate\Http\JsonResponse;

class BudgetController extends ReportingController
{
    public function __construct(PeriodeResolver $periodeResolver, private readonly BudgetReportService $service)
    {
        parent::__construct($periodeResolver);
    }

    public function consommation(ReportingRequest $request): JsonResponse
    {
        $this->authorizeReporting('reporting.budget');

        $sites = $this->siteScope($request);
        $periode = $this->periode($request->validated());

        $budgets = $this->service->consommation(
            $periode,
            $sites,
        );

        return ApiResponse::success(
            ['budgets' => BudgetResource::collection($budgets)],
            'Consommation budgétaire récupérée.'
        );
    }

    public function depenses(ReportingRequest $request): JsonResponse
    {
        $this->authorizeReporting('reporting.budget');

        $sites = $this->siteScope($request);
        $periode = $this->periode($request->validated());

        $data = $this->service->depenses(
            $periode,
            $sites,
        );

        return ApiResponse::success($data, 'Dépenses détaillées récupérées.');
    }
}
