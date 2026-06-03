<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Produit\StoreProduitRequest;
use App\Http\Requests\Produit\UpdateProduitRequest;
use App\Http\Resources\ProduitResource;
use App\Models\Produit;
use App\Services\ProduitService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProduitController extends Controller
{
    public function __construct(private readonly ProduitService $service) {}

    /**
     * Liste les produits (filtres optionnels ?categorie_id et ?statut).
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Produit::class);

        $produits = Produit::query()
            ->with('categorie')
            ->when(
                $request->filled('categorie_id'),
                fn (Builder $query) => $query->where('categorie_id', $request->integer('categorie_id'))
            )
            ->when(
                $request->filled('statut'),
                fn (Builder $query) => $query->where('statut', $request->string('statut'))
            )
            ->orderByDesc('id')
            ->paginate($request->integer('per_page', 15));

        return ApiResponse::success([
            'produits' => ProduitResource::collection($produits->items()),
            'pagination' => [
                'total' => $produits->total(),
                'par_page' => $produits->perPage(),
                'page_courante' => $produits->currentPage(),
                'derniere_page' => $produits->lastPage(),
            ],
        ], 'Produits récupérés.');
    }

    public function store(StoreProduitRequest $request): JsonResponse
    {
        $produit = $this->service->creer($request->validated());

        return ApiResponse::created(
            new ProduitResource($produit),
            'Produit enregistré.'
        );
    }

    public function show(Produit $produit): JsonResponse
    {
        $this->authorize('view', $produit);

        return ApiResponse::success(
            new ProduitResource($produit->load('categorie')),
            'Produit récupéré.'
        );
    }

    public function update(UpdateProduitRequest $request, Produit $produit): JsonResponse
    {
        $produit = $this->service->modifier($produit, $request->validated());

        return ApiResponse::updated(
            new ProduitResource($produit),
            'Produit mis à jour.'
        );
    }

    /**
     * Soft delete, refusé (409) si le produit est en fiche technique active.
     */
    public function destroy(Produit $produit): JsonResponse
    {
        $this->authorize('delete', $produit);

        $this->service->supprimer($produit);

        return ApiResponse::deleted('Produit supprimé.');
    }
}
