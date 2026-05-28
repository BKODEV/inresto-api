<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\PointDeVente\StorePointDeVenteRequest;
use App\Http\Requests\PointDeVente\UpdatePointDeVenteRequest;
use App\Http\Resources\PointDeVenteResource;
use App\Models\PointDeVente;
use Illuminate\Http\JsonResponse;

class PointDeVenteController extends Controller
{
    /**
     * Liste tous les points de vente.
     */
    public function index(): JsonResponse
    {
        $this->authorize('viewAny', PointDeVente::class);

        return ApiResponse::success(
            PointDeVenteResource::collection(PointDeVente::all()),
            'Points de vente récupérés.'
        );
    }

    /**
     * Crée un nouveau point de vente.
     */
    public function store(StorePointDeVenteRequest $request): JsonResponse
    {
        $pointDeVente = PointDeVente::create($request->validated());

        return ApiResponse::created(
            new PointDeVenteResource($pointDeVente),
            'Point de vente créé avec succès.'
        );
    }

    /**
     * Affiche un point de vente.
     */
    public function show(PointDeVente $pointDeVente): JsonResponse
    {
        $this->authorize('view', $pointDeVente);

        return ApiResponse::success(
            new PointDeVenteResource($pointDeVente),
            'Point de vente récupéré.'
        );
    }

    /**
     * Met à jour un point de vente.
     */
    public function update(UpdatePointDeVenteRequest $request, PointDeVente $pointDeVente): JsonResponse
    {
        $pointDeVente->update($request->validated());

        return ApiResponse::updated(
            new PointDeVenteResource($pointDeVente),
            'Point de vente mis à jour avec succès.'
        );
    }

    /**
     * Supprime un point de vente.
     */
    public function destroy(PointDeVente $pointDeVente): JsonResponse
    {
        $this->authorize('delete', $pointDeVente);

        $pointDeVente->delete();

        return ApiResponse::deleted('Point de vente supprimé avec succès.');
    }
}
