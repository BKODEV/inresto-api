<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Parametres;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Carte\StoreCarteRequest;
use App\Http\Requests\Carte\UpdateCarteRequest;
use App\Http\Resources\CarteResource;
use App\Models\Carte;
use Illuminate\Http\JsonResponse;

class CarteController extends Controller
{
    public function index(): JsonResponse
    {
        $this->authorize('viewAny', Carte::class);

        return ApiResponse::success(
            CarteResource::collection(Carte::with('pointDeVente')->get()),
            'Cartes récupérées.'
        );
    }

    public function store(StoreCarteRequest $request): JsonResponse
    {
        $carte = Carte::create($request->validated());
        $carte->load('pointDeVente');

        return ApiResponse::created(
            new CarteResource($carte),
            'Carte créée avec succès.'
        );
    }

    public function show(Carte $carte): JsonResponse
    {
        $this->authorize('view', $carte);

        $carte->load('pointDeVente');

        return ApiResponse::success(
            new CarteResource($carte),
            'Carte récupérée.'
        );
    }

    public function update(UpdateCarteRequest $request, Carte $carte): JsonResponse
    {
        $carte->update($request->validated());
        $carte->load('pointDeVente');

        return ApiResponse::updated(
            new CarteResource($carte),
            'Carte mise à jour avec succès.'
        );
    }

    public function destroy(Carte $carte): JsonResponse
    {
        $this->authorize('delete', $carte);

        $carte->delete();

        return ApiResponse::deleted('Carte supprimée avec succès.');
    }
}
