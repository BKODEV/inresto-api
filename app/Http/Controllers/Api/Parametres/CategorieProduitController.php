<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Parametres;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\CategorieProduit\StoreCategorieProduitRequest;
use App\Http\Requests\CategorieProduit\UpdateCategorieProduitRequest;
use App\Http\Resources\CategorieProduitResource;
use App\Models\CategorieProduit;
use Illuminate\Http\JsonResponse;

class CategorieProduitController extends Controller
{
    public function index(): JsonResponse
    {
        $this->authorize('viewAny', CategorieProduit::class);

        return ApiResponse::success(
            CategorieProduitResource::collection(CategorieProduit::all()),
            'Catégories produit récupérées.'
        );
    }

    public function store(StoreCategorieProduitRequest $request): JsonResponse
    {
        $categorie = CategorieProduit::create($request->validated());

        return ApiResponse::created(
            new CategorieProduitResource($categorie),
            'Catégorie produit créée avec succès.'
        );
    }

    public function show(CategorieProduit $categorieProduit): JsonResponse
    {
        $this->authorize('view', $categorieProduit);

        return ApiResponse::success(
            new CategorieProduitResource($categorieProduit),
            'Catégorie produit récupérée.'
        );
    }

    public function update(UpdateCategorieProduitRequest $request, CategorieProduit $categorieProduit): JsonResponse
    {
        $categorieProduit->update($request->validated());

        return ApiResponse::updated(
            new CategorieProduitResource($categorieProduit),
            'Catégorie produit mise à jour avec succès.'
        );
    }

    public function destroy(CategorieProduit $categorieProduit): JsonResponse
    {
        $this->authorize('delete', $categorieProduit);

        $categorieProduit->delete();

        return ApiResponse::deleted('Catégorie produit supprimée avec succès.');
    }
}
