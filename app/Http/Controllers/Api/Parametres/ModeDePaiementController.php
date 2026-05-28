<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Parametres;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\ModeDePaiement\StoreModeDePaiementRequest;
use App\Http\Requests\ModeDePaiement\UpdateModeDePaiementRequest;
use App\Http\Resources\ModeDePaiementResource;
use App\Models\ModeDePaiement;
use Illuminate\Http\JsonResponse;

class ModeDePaiementController extends Controller
{
    public function index(): JsonResponse
    {
        $this->authorize('viewAny', ModeDePaiement::class);

        return ApiResponse::success(
            ModeDePaiementResource::collection(ModeDePaiement::all()),
            'Modes de paiement récupérés.'
        );
    }

    public function store(StoreModeDePaiementRequest $request): JsonResponse
    {
        $mode = ModeDePaiement::create($request->validated());

        return ApiResponse::created(
            new ModeDePaiementResource($mode),
            'Mode de paiement créé avec succès.'
        );
    }

    public function show(ModeDePaiement $modeDePaiement): JsonResponse
    {
        $this->authorize('view', $modeDePaiement);

        return ApiResponse::success(
            new ModeDePaiementResource($modeDePaiement),
            'Mode de paiement récupéré.'
        );
    }

    public function update(UpdateModeDePaiementRequest $request, ModeDePaiement $modeDePaiement): JsonResponse
    {
        $modeDePaiement->update($request->validated());

        return ApiResponse::updated(
            new ModeDePaiementResource($modeDePaiement),
            'Mode de paiement mis à jour avec succès.'
        );
    }

    public function destroy(ModeDePaiement $modeDePaiement): JsonResponse
    {
        $this->authorize('delete', $modeDePaiement);

        $modeDePaiement->delete();

        return ApiResponse::deleted('Mode de paiement supprimé avec succès.');
    }
}
