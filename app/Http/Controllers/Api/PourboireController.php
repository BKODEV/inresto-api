<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Versement;
use App\Services\PourboireService;
use App\Support\SitePerimetre;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PourboireController extends Controller
{
    public function __construct(private readonly PourboireService $service) {}

    /**
     * Ce qui reste dû à chacun sur les pourboires encaissés au comptoir.
     *
     * Gardé par `versement.view` : lire ce qu'on doit au personnel relève du
     * même geste que voir sortir l'argent du tiroir.
     */
    public function soldes(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Versement::class);

        $sites = SitePerimetre::resoudre($request->user(), null);

        return ApiResponse::success(
            ['soldes' => $this->service->soldesParServeur($sites)],
            'Soldes de pourboires récupérés.'
        );
    }
}
