<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Parametres;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\UniteDeMesure\StoreUniteDeMesureRequest;
use App\Http\Requests\UniteDeMesure\UpdateUniteDeMesureRequest;
use App\Http\Resources\UniteDeMesureResource;
use App\Models\UniteDeMesure;
use Illuminate\Http\JsonResponse;

class UniteDeMesureController extends Controller
{
    public function index(): JsonResponse
    {
        $this->authorize('viewAny', UniteDeMesure::class);

        return ApiResponse::success(
            UniteDeMesureResource::collection(UniteDeMesure::all()),
            'Unités de mesure récupérées.'
        );
    }

    public function store(StoreUniteDeMesureRequest $request): JsonResponse
    {
        $unite = UniteDeMesure::create($request->validated());

        return ApiResponse::created(
            new UniteDeMesureResource($unite),
            'Unité de mesure créée avec succès.'
        );
    }

    public function show(UniteDeMesure $uniteDeMesure): JsonResponse
    {
        $this->authorize('view', $uniteDeMesure);

        return ApiResponse::success(
            new UniteDeMesureResource($uniteDeMesure),
            'Unité de mesure récupérée.'
        );
    }

    public function update(UpdateUniteDeMesureRequest $request, UniteDeMesure $uniteDeMesure): JsonResponse
    {
        $uniteDeMesure->update($request->validated());

        return ApiResponse::updated(
            new UniteDeMesureResource($uniteDeMesure),
            'Unité de mesure mise à jour avec succès.'
        );
    }

    public function destroy(UniteDeMesure $uniteDeMesure): JsonResponse
    {
        $this->authorize('delete', $uniteDeMesure);

        $uniteDeMesure->delete();

        return ApiResponse::deleted('Unité de mesure supprimée avec succès.');
    }
}
