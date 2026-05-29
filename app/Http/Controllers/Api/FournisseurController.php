<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Fournisseur\StoreFournisseurRequest;
use App\Http\Requests\Fournisseur\UpdateFournisseurRequest;
use App\Http\Resources\FournisseurResource;
use App\Models\Fournisseur;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FournisseurController extends Controller
{
    /**
     * Liste les fournisseurs (recherche par nom, filtre par statut, pagination).
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Fournisseur::class);

        $fournisseurs = Fournisseur::query()
            ->when(
                $request->filled('nom'),
                fn (Builder $query) => $query->where('nom', 'like', '%'.$request->string('nom').'%')
            )
            ->when(
                $request->filled('statut'),
                fn (Builder $query) => $query->where('statut', $request->string('statut'))
            )
            ->orderBy('nom')
            ->paginate($request->integer('per_page', 15));

        return ApiResponse::success([
            'fournisseurs' => FournisseurResource::collection($fournisseurs->items()),
            'pagination' => [
                'total' => $fournisseurs->total(),
                'par_page' => $fournisseurs->perPage(),
                'page_courante' => $fournisseurs->currentPage(),
                'derniere_page' => $fournisseurs->lastPage(),
            ],
        ], 'Fournisseurs récupérés.');
    }

    /**
     * Crée un nouveau fournisseur.
     */
    public function store(StoreFournisseurRequest $request): JsonResponse
    {
        $fournisseur = Fournisseur::create($request->validated());

        return ApiResponse::created(
            new FournisseurResource($fournisseur),
            'Fournisseur créé avec succès.'
        );
    }

    /**
     * Affiche un fournisseur.
     */
    public function show(Fournisseur $fournisseur): JsonResponse
    {
        $this->authorize('view', $fournisseur);

        return ApiResponse::success(
            new FournisseurResource($fournisseur),
            'Fournisseur récupéré.'
        );
    }

    /**
     * Met à jour un fournisseur.
     */
    public function update(UpdateFournisseurRequest $request, Fournisseur $fournisseur): JsonResponse
    {
        $fournisseur->update($request->validated());

        return ApiResponse::updated(
            new FournisseurResource($fournisseur),
            'Fournisseur mis à jour avec succès.'
        );
    }

    /**
     * Supprime un fournisseur (soft delete).
     */
    public function destroy(Fournisseur $fournisseur): JsonResponse
    {
        $this->authorize('delete', $fournisseur);

        $fournisseur->delete();

        return ApiResponse::deleted('Fournisseur supprimé avec succès.');
    }
}
