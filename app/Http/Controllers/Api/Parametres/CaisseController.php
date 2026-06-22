<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Parametres;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Caisse\StoreCaisseRequest;
use App\Http\Requests\Caisse\UpdateCaisseRequest;
use App\Http\Resources\CaisseResource;
use App\Models\Caisse;
use Illuminate\Http\JsonResponse;

class CaisseController extends Controller
{
    public function index(): JsonResponse
    {
        $this->authorize('viewAny', Caisse::class);

        return ApiResponse::success(
            CaisseResource::collection(Caisse::with('pointDeVente')->get()),
            'Caisses récupérées.'
        );
    }

    public function store(StoreCaisseRequest $request): JsonResponse
    {
        $caisse = Caisse::create($request->validated());
        $caisse->load('pointDeVente');

        return ApiResponse::created(
            new CaisseResource($caisse),
            'Caisse créée avec succès.'
        );
    }

    public function show(Caisse $caisse): JsonResponse
    {
        $this->authorize('view', $caisse);

        $caisse->load('pointDeVente');

        return ApiResponse::success(
            new CaisseResource($caisse),
            'Caisse récupérée.'
        );
    }

    public function update(UpdateCaisseRequest $request, Caisse $caisse): JsonResponse
    {
        $caisse->update($request->validated());
        $caisse->load('pointDeVente');

        return ApiResponse::updated(
            new CaisseResource($caisse),
            'Caisse mise à jour avec succès.'
        );
    }

    public function destroy(Caisse $caisse): JsonResponse
    {
        $this->authorize('delete', $caisse);

        // Une caisse porteuse de sessions (donc de ventes/paiements) ne peut être
        // supprimée : on protège l'intégrité de l'historique financier.
        if ($caisse->sessions()->exists()) {
            return ApiResponse::custom(
                409,
                'error',
                'Cette caisse a un historique de sessions et ne peut être supprimée.'
            );
        }

        $caisse->delete();

        return ApiResponse::deleted('Caisse supprimée avec succès.');
    }
}
