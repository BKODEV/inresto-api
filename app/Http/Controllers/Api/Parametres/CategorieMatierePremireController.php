<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Parametres;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\CategorieMatierePremiere\StoreCategorieMatierePremireRequest;
use App\Http\Requests\CategorieMatierePremiere\UpdateCategorieMatierePremireRequest;
use App\Http\Resources\CategorieMatierePremireResource;
use App\Models\CategorieMatierePremiere;
use Illuminate\Http\JsonResponse;

class CategorieMatierePremireController extends Controller
{
    public function index(): JsonResponse
    {
        $this->authorize('viewAny', CategorieMatierePremiere::class);

        return ApiResponse::success(
            CategorieMatierePremireResource::collection(CategorieMatierePremiere::all()),
            'Catégories matière première récupérées.'
        );
    }

    public function store(StoreCategorieMatierePremireRequest $request): JsonResponse
    {
        $categorie = CategorieMatierePremiere::create($request->validated());

        return ApiResponse::created(
            new CategorieMatierePremireResource($categorie),
            'Catégorie matière première créée avec succès.'
        );
    }

    public function show(CategorieMatierePremiere $categorieMatierePremire): JsonResponse
    {
        $this->authorize('view', $categorieMatierePremire);

        return ApiResponse::success(
            new CategorieMatierePremireResource($categorieMatierePremire),
            'Catégorie matière première récupérée.'
        );
    }

    public function update(UpdateCategorieMatierePremireRequest $request, CategorieMatierePremiere $categorieMatierePremire): JsonResponse
    {
        $categorieMatierePremire->update($request->validated());

        return ApiResponse::updated(
            new CategorieMatierePremireResource($categorieMatierePremire),
            'Catégorie matière première mise à jour avec succès.'
        );
    }

    public function destroy(CategorieMatierePremiere $categorieMatierePremire): JsonResponse
    {
        $this->authorize('delete', $categorieMatierePremire);

        $categorieMatierePremire->delete();

        return ApiResponse::deleted('Catégorie matière première supprimée avec succès.');
    }
}
