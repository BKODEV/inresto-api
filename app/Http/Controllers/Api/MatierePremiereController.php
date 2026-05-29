<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\MatierePremiere\StoreMatierePremiereRequest;
use App\Http\Requests\MatierePremiere\UpdateMatierePremiereRequest;
use App\Http\Resources\MatierePremiereResource;
use App\Models\MatierePremiere;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MatierePremiereController extends Controller
{
    /**
     * Liste les matières premières (recherche par libellé, filtres catégorie et statut, pagination).
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', MatierePremiere::class);

        $matieresPremieres = MatierePremiere::query()
            ->with(['categorie', 'unite'])
            ->when(
                $request->filled('libelle'),
                fn (Builder $query) => $query->where('libelle', 'like', '%'.$request->string('libelle').'%')
            )
            ->when(
                $request->filled('categorie_id'),
                fn (Builder $query) => $query->where('categorie_id', $request->integer('categorie_id'))
            )
            ->when(
                $request->filled('statut'),
                fn (Builder $query) => $query->where('statut', $request->string('statut'))
            )
            ->orderBy('libelle')
            ->paginate($request->integer('per_page', 15));

        return ApiResponse::success([
            'matieres_premieres' => MatierePremiereResource::collection($matieresPremieres->items()),
            'pagination' => [
                'total' => $matieresPremieres->total(),
                'par_page' => $matieresPremieres->perPage(),
                'page_courante' => $matieresPremieres->currentPage(),
                'derniere_page' => $matieresPremieres->lastPage(),
            ],
        ], 'Matières premières récupérées.');
    }

    /**
     * Crée une nouvelle matière première.
     */
    public function store(StoreMatierePremiereRequest $request): JsonResponse
    {
        $matierePremiere = MatierePremiere::create($request->validated());
        $matierePremiere->load(['categorie', 'unite']);

        return ApiResponse::created(
            new MatierePremiereResource($matierePremiere),
            'Matière première créée avec succès.'
        );
    }

    /**
     * Affiche une matière première.
     */
    public function show(MatierePremiere $matiere_premiere): JsonResponse
    {
        $this->authorize('view', $matiere_premiere);

        $matiere_premiere->load(['categorie', 'unite']);

        return ApiResponse::success(
            new MatierePremiereResource($matiere_premiere),
            'Matière première récupérée.'
        );
    }

    /**
     * Met à jour une matière première (stock_courant et pmp ne sont jamais modifiables ici).
     */
    public function update(UpdateMatierePremiereRequest $request, MatierePremiere $matiere_premiere): JsonResponse
    {
        $matiere_premiere->update($request->validated());
        $matiere_premiere->load(['categorie', 'unite']);

        return ApiResponse::updated(
            new MatierePremiereResource($matiere_premiere),
            'Matière première mise à jour avec succès.'
        );
    }

    /**
     * Supprime une matière première (soft delete).
     */
    public function destroy(MatierePremiere $matiere_premiere): JsonResponse
    {
        $this->authorize('delete', $matiere_premiere);

        $matiere_premiere->delete();

        return ApiResponse::deleted('Matière première supprimée avec succès.');
    }
}
