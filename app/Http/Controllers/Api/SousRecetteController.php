<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Exceptions\SousRecetteException;
use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\SousRecette\StoreSousRecetteRequest;
use App\Http\Requests\SousRecette\UpdateSousRecetteRequest;
use App\Http\Resources\SousRecetteResource;
use App\Models\SousRecette;
use App\Services\SousRecetteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SousRecetteController extends Controller
{
    public function __construct(private readonly SousRecetteService $service) {}

    /**
     * Liste les sous-recettes (pagination).
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', SousRecette::class);

        $sousRecettes = SousRecette::query()
            ->with(['unite', 'lignes.matierePremiere', 'lignes.unite'])
            ->orderBy('libelle')
            ->paginate($request->integer('per_page', 15));

        return ApiResponse::success([
            'sous_recettes' => SousRecetteResource::collection($sousRecettes->items()),
            'pagination' => [
                'total' => $sousRecettes->total(),
                'par_page' => $sousRecettes->perPage(),
                'page_courante' => $sousRecettes->currentPage(),
                'derniere_page' => $sousRecettes->lastPage(),
            ],
        ], 'Sous-recettes récupérées.');
    }

    /**
     * Crée une sous-recette avec ses lignes (coût matière calculé).
     */
    public function store(StoreSousRecetteRequest $request): JsonResponse
    {
        $sousRecette = $this->service->creer($request->validated(), $request->user()->id);

        return ApiResponse::created(
            new SousRecetteResource($sousRecette),
            'Sous-recette créée avec succès.'
        );
    }

    /**
     * Affiche une sous-recette avec ses lignes.
     */
    public function show(SousRecette $sous_recette): JsonResponse
    {
        $this->authorize('view', $sous_recette);

        $sous_recette->load(['unite', 'lignes.matierePremiere', 'lignes.unite']);

        return ApiResponse::success(
            new SousRecetteResource($sous_recette),
            'Sous-recette récupérée.'
        );
    }

    /**
     * Met à jour une sous-recette (remplacement complet des lignes + recalcul).
     */
    public function update(UpdateSousRecetteRequest $request, SousRecette $sous_recette): JsonResponse
    {
        $sousRecette = $this->service->modifier($sous_recette, $request->validated(), $request->user()->id);

        return ApiResponse::updated(
            new SousRecetteResource($sousRecette),
            'Sous-recette mise à jour avec succès.'
        );
    }

    /**
     * Supprime une sous-recette — interdit si utilisée dans une fiche technique.
     */
    public function destroy(SousRecette $sous_recette): JsonResponse
    {
        $this->authorize('delete', $sous_recette);

        if ($sous_recette->estUtiliseeDansFicheTechnique()) {
            throw SousRecetteException::utiliseeDansFicheTechnique($sous_recette->id);
        }

        $sous_recette->delete();

        return ApiResponse::noContent();
    }
}
