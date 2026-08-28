<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Parametres;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\PosteCuisine\StorePosteCuisineRequest;
use App\Http\Requests\PosteCuisine\UpdatePosteCuisineRequest;
use App\Http\Resources\PosteCuisineResource;
use App\Models\PosteCuisine;
use App\Support\SitePerimetre;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Paramétrage des postes de production et de leurs catégories.
 *
 * Le rattachement des catégories est écrit par `sync()` : c'est un ENSEMBLE que
 * l'écran de paramétrage repose en entier, pas une liste à laquelle on ajoute.
 */
class PosteCuisineController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', PosteCuisine::class);

        $sites = SitePerimetre::resoudre($request->user(), null);

        $postes = PosteCuisine::query()
            ->with(['categories', 'pointDeVente'])
            ->when(
                $sites !== null,
                fn (Builder $query) => $query->whereHas(
                    'pointDeVente',
                    fn (Builder $pdv) => $pdv->whereIn('site_id', $sites)
                )
            )
            ->when(
                $request->filled('point_de_vente_id'),
                fn (Builder $query) => $query->where('point_de_vente_id', $request->integer('point_de_vente_id'))
            )
            ->orderBy('point_de_vente_id')
            ->orderBy('ordre')
            ->orderBy('id')
            ->get();

        return ApiResponse::success(
            PosteCuisineResource::collection($postes),
            'Postes de cuisine récupérés.'
        );
    }

    public function store(StorePosteCuisineRequest $request): JsonResponse
    {
        $data = $request->validated();

        $poste = DB::transaction(function () use ($data): PosteCuisine {
            $poste = PosteCuisine::create(collect($data)->except('categories')->all());
            $poste->categories()->sync($data['categories'] ?? []);

            return $poste;
        });

        return ApiResponse::created(
            new PosteCuisineResource($poste->load(['categories', 'pointDeVente'])),
            'Poste de cuisine créé.'
        );
    }

    public function show(PosteCuisine $poste_cuisine): JsonResponse
    {
        $this->authorize('view', $poste_cuisine);

        return ApiResponse::success(
            new PosteCuisineResource($poste_cuisine->load(['categories', 'pointDeVente'])),
            'Poste de cuisine récupéré.'
        );
    }

    public function update(UpdatePosteCuisineRequest $request, PosteCuisine $poste_cuisine): JsonResponse
    {
        $data = $request->validated();

        DB::transaction(function () use ($data, $poste_cuisine): void {
            $poste_cuisine->update(collect($data)->except('categories')->all());

            // `sync` seulement si la clé est présente : absente, le rattachement
            // ne bouge pas ; vide, on détache tout — deux intentions distinctes
            // qu'un `?? []` confondrait.
            if (array_key_exists('categories', $data)) {
                $poste_cuisine->categories()->sync($data['categories']);
            }
        });

        return ApiResponse::updated(
            new PosteCuisineResource($poste_cuisine->load(['categories', 'pointDeVente'])),
            'Poste de cuisine mis à jour.'
        );
    }

    public function destroy(PosteCuisine $poste_cuisine): JsonResponse
    {
        $this->authorize('delete', $poste_cuisine);

        // Rien à protéger : un poste ne porte aucun historique, seulement un
        // rattachement de catégories que la cascade emporte. Le supprimer
        // renvoie ses plats sur tous les écrans, jamais dans le vide.
        $poste_cuisine->delete();

        return ApiResponse::deleted('Poste de cuisine supprimé.');
    }
}
