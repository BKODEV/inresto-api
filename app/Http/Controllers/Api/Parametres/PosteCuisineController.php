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
 * Le rattachement des catégories ET des membres est écrit par `sync()` : ce sont
 * des ENSEMBLES que l'écran repose en entier, pas des listes à compléter.
 *
 * Un poste porte donc les deux moitiés de l'organisation du pass : ce qu'on y
 * produit (catégories) et qui le tient (membres).
 */
class PosteCuisineController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', PosteCuisine::class);

        $sites = SitePerimetre::resoudre($request->user(), null);

        $postes = PosteCuisine::query()
            ->with(['categories', 'membres', 'pointDeVente'])
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
            $pointDeVenteId = (int) $data['point_de_vente_id'];

            // Le premier poste d'un pass en devient le poste par défaut : il n'y
            // a jamais de point de vente avec des postes mais sans destination
            // pour ce que personne ne produit.
            $data['par_defaut'] = ($data['par_defaut'] ?? false)
                || ! PosteCuisine::query()->where('point_de_vente_id', $pointDeVenteId)->exists();

            if ($data['par_defaut']) {
                $this->retirerLeDefaut($pointDeVenteId);
            }

            $poste = PosteCuisine::create(collect($data)->except(['categories', 'membres'])->all());
            $poste->categories()->sync($data['categories'] ?? []);
            $poste->membres()->sync($data['membres'] ?? []);

            return $poste;
        });

        return ApiResponse::created(
            new PosteCuisineResource($poste->load(['categories', 'membres', 'pointDeVente'])),
            'Poste de cuisine créé.'
        );
    }

    public function show(PosteCuisine $poste_cuisine): JsonResponse
    {
        $this->authorize('view', $poste_cuisine);

        return ApiResponse::success(
            new PosteCuisineResource($poste_cuisine->load(['categories', 'membres', 'pointDeVente'])),
            'Poste de cuisine récupéré.'
        );
    }

    public function update(UpdatePosteCuisineRequest $request, PosteCuisine $poste_cuisine): JsonResponse
    {
        $data = $request->validated();

        DB::transaction(function () use ($data, $poste_cuisine): void {
            // Désigner un poste par défaut retire la charge à l'ancien : il n'y
            // en a qu'un par pass. Le retrait direct est refusé à la validation.
            if (($data['par_defaut'] ?? false) && ! $poste_cuisine->par_defaut) {
                $this->retirerLeDefaut($poste_cuisine->point_de_vente_id);
            }

            $poste_cuisine->update(collect($data)->except(['categories', 'membres'])->all());

            // `sync` seulement si la clé est présente : absente, le rattachement
            // ne bouge pas ; vide, on détache tout — deux intentions distinctes
            // qu'un `?? []` confondrait. Vrai des catégories comme des membres.
            if (array_key_exists('categories', $data)) {
                $poste_cuisine->categories()->sync($data['categories']);
            }

            if (array_key_exists('membres', $data)) {
                $poste_cuisine->membres()->sync($data['membres']);
            }
        });

        return ApiResponse::updated(
            new PosteCuisineResource($poste_cuisine->load(['categories', 'membres', 'pointDeVente'])),
            'Poste de cuisine mis à jour.'
        );
    }

    public function destroy(PosteCuisine $poste_cuisine): JsonResponse
    {
        $this->authorize('delete', $poste_cuisine);

        // Le poste par défaut reçoit ce que personne d'autre ne produit : tant
        // qu'il reste d'autres postes, il faut lui désigner un successeur avant
        // de le supprimer. Seul, il peut partir — le pass revient au bon unique
        // « Cuisine ».
        $autresPostes = PosteCuisine::query()
            ->where('point_de_vente_id', $poste_cuisine->point_de_vente_id)
            ->whereKeyNot($poste_cuisine->id)
            ->exists();

        if ($poste_cuisine->par_defaut && $autresPostes) {
            return ApiResponse::custom(
                409,
                'error',
                'Désignez un autre poste par défaut avant de supprimer celui-ci.'
            );
        }

        // Un poste ne porte que des rattachements — catégories et membres — que
        // la cascade emporte. Ses plats repartent au poste par défaut sur les
        // bons, sur tous les écrans au KDS, jamais dans le vide ; ses membres
        // restent du personnel du site. Les bons déjà émis gardent son libellé.
        $poste_cuisine->delete();

        return ApiResponse::deleted('Poste de cuisine supprimé.');
    }

    /** Le poste par défaut est unique par pass : on retire la charge avant de la confier. */
    private function retirerLeDefaut(int $pointDeVenteId): void
    {
        PosteCuisine::query()
            ->where('point_de_vente_id', $pointDeVenteId)
            ->where('par_defaut', true)
            ->update(['par_defaut' => false]);
    }
}
