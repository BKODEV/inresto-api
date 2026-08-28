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
use Illuminate\Http\JsonResponse;

class VersementController extends Controller
{
    public function __construct(private readonly VersementService $service) {}

    /**
     * Versements d'une caisse, du plus récent au plus ancien.
     */
    public function index(Caisse $caisse): JsonResponse
    {
        $this->authorize('viewAny', Versement::class);

        $versements = $caisse->versements()
            ->with(['user', 'session'])
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
