<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Parametres;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\CategorieProduit\StoreCategorieProduitRequest;
use App\Http\Requests\CategorieProduit\UpdateCategorieProduitRequest;
use App\Http\Resources\CategorieProduitResource;
use App\Models\CategorieProduit;
use App\Services\OrdreService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class CategorieProduitController extends Controller
{
    public function index(): JsonResponse
    {
        $this->authorize('viewAny', CategorieProduit::class);

        return ApiResponse::success(
            CategorieProduitResource::collection(
                CategorieProduit::orderBy('ordre')->orderBy('id')->get()
            ),
            'Catégories produit récupérées.'
        );
    }

    public function store(StoreCategorieProduitRequest $request, OrdreService $ordreService): JsonResponse
    {
        $data = $request->validated();

        $categorie = DB::transaction(function () use ($data, $ordreService) {
            $data['ordre'] = $ordreService->placer(
                fn () => CategorieProduit::query(),
                $data['ordre'] ?? null,
            );

            return CategorieProduit::create($data);
        });

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

    public function update(UpdateCategorieProduitRequest $request, CategorieProduit $categorieProduit, OrdreService $ordreService): JsonResponse
    {
        $data = $request->validated();

        DB::transaction(function () use ($categorieProduit, $data, $ordreService) {
            if (array_key_exists('ordre', $data) && (int) $data['ordre'] !== $categorieProduit->ordre) {
                $data['ordre'] = $ordreService->placer(
                    fn () => CategorieProduit::query()->whereKeyNot($categorieProduit->id),
                    $data['ordre'],
                );
            }

            $categorieProduit->update($data);
        });

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
