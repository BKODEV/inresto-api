<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Versement\StoreVersementRequest;
use App\Http\Resources\VersementResource;
use App\Models\Caisse;
use App\Models\Versement;
use App\Services\VersementService;
use App\Support\SitePerimetre;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VersementController extends Controller
{
    public function __construct(private readonly VersementService $service) {}

    /**
     * Versements d'une caisse, du plus récent au plus ancien.
     */
    public function index(Request $request, Caisse $caisse): JsonResponse
    {
        $this->authorize('viewAny', Versement::class);

        // Le tiroir désigne le site : lire les sorties d'argent d'un
        // établissement hors de son périmètre n'a pas plus de sens que de les
        // provoquer. La levée de SitePerimetre suffit, on n'exploite pas le retour.
        SitePerimetre::resoudre($request->user(), $caisse->siteId());

        $versements = $caisse->versements()
            // `beneficiaire` : un reversement de pourboires nomme la personne
            // payée, et la liste doit dire à qui l'argent est parti.
            ->with(['user', 'session', 'beneficiaire'])
            ->orderByDesc('date_heure')
            ->limit(100)
            ->get();

        return ApiResponse::success(
            VersementResource::collection($versements),
            'Versements récupérés.'
        );
    }

    public function store(StoreVersementRequest $request, Caisse $caisse): JsonResponse
    {
        $versement = $this->service->enregistrer($caisse, $request->validated(), $request->user()->id);

        return ApiResponse::created(
            new VersementResource($versement),
            'Versement enregistré.'
        );
    }
}
