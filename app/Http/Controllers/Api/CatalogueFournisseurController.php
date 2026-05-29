<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\CatalogueFournisseur\StoreCatalogueFournisseurRequest;
use App\Http\Requests\CatalogueFournisseur\SyncCatalogueFournisseurRequest;
use App\Http\Requests\CatalogueFournisseur\UpdateCatalogueFournisseurRequest;
use App\Http\Resources\CatalogueFournisseurResource;
use App\Models\CatalogueFournisseur;
use App\Models\Fournisseur;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CatalogueFournisseurController extends Controller
{
    /**
     * Liste le catalogue fournisseur (filtres par fournisseur et matière première, pagination).
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', CatalogueFournisseur::class);

        $lignes = CatalogueFournisseur::query()
            ->with(['fournisseur', 'matierePremiere', 'uniteAchat'])
            ->when(
                $request->filled('fournisseur_id'),
                fn (Builder $query) => $query->where('fournisseur_id', $request->integer('fournisseur_id'))
            )
            ->when(
                $request->filled('matiere_premiere_id'),
                fn (Builder $query) => $query->where('matiere_premiere_id', $request->integer('matiere_premiere_id'))
            )
            ->paginate($request->integer('per_page', 15));

        return ApiResponse::success([
            'catalogue' => CatalogueFournisseurResource::collection($lignes->items()),
            'pagination' => [
                'total' => $lignes->total(),
                'par_page' => $lignes->perPage(),
                'page_courante' => $lignes->currentPage(),
                'derniere_page' => $lignes->lastPage(),
            ],
        ], 'Catalogue fournisseur récupéré.');
    }

    /**
     * Ajoute une ligne au catalogue fournisseur.
     */
    public function store(StoreCatalogueFournisseurRequest $request): JsonResponse
    {
        $ligne = CatalogueFournisseur::create($request->validated());
        $ligne->load(['fournisseur', 'matierePremiere', 'uniteAchat']);

        return ApiResponse::created(
            new CatalogueFournisseurResource($ligne),
            'Ligne de catalogue créée avec succès.'
        );
    }

    /**
     * Affiche une ligne du catalogue fournisseur.
     */
    public function show(CatalogueFournisseur $catalogue_fournisseur): JsonResponse
    {
        $this->authorize('view', $catalogue_fournisseur);

        $catalogue_fournisseur->load(['fournisseur', 'matierePremiere', 'uniteAchat']);

        return ApiResponse::success(
            new CatalogueFournisseurResource($catalogue_fournisseur),
            'Ligne de catalogue récupérée.'
        );
    }

    /**
     * Met à jour une ligne du catalogue fournisseur.
     */
    public function update(UpdateCatalogueFournisseurRequest $request, CatalogueFournisseur $catalogue_fournisseur): JsonResponse
    {
        $catalogue_fournisseur->update($request->validated());
        $catalogue_fournisseur->load(['fournisseur', 'matierePremiere', 'uniteAchat']);

        return ApiResponse::updated(
            new CatalogueFournisseurResource($catalogue_fournisseur),
            'Ligne de catalogue mise à jour avec succès.'
        );
    }

    /**
     * Supprime une ligne du catalogue fournisseur.
     */
    public function destroy(CatalogueFournisseur $catalogue_fournisseur): JsonResponse
    {
        $this->authorize('delete', $catalogue_fournisseur);

        $catalogue_fournisseur->delete();

        return ApiResponse::deleted('Ligne de catalogue supprimée avec succès.');
    }

    /**
     * Remplace l'intégralité du catalogue d'un fournisseur par l'ensemble fourni.
     *
     * Les lignes existantes sont supprimées puis recréées à partir du payload :
     * le client envoie l'état complet souhaité, le serveur le reflète à l'identique.
     */
    public function sync(SyncCatalogueFournisseurRequest $request, Fournisseur $fournisseur): JsonResponse
    {
        /** @var array<int, array{matiere_premiere_id: int, prix_unitaire: float|string, unite_achat_id: int}> $lignes */
        $lignes = $request->validated()['lignes'];

        $catalogue = DB::transaction(function () use ($fournisseur, $lignes) {
            CatalogueFournisseur::where('fournisseur_id', $fournisseur->id)->delete();

            foreach ($lignes as $ligne) {
                CatalogueFournisseur::create([
                    'fournisseur_id' => $fournisseur->id,
                    'matiere_premiere_id' => $ligne['matiere_premiere_id'],
                    'prix_unitaire' => $ligne['prix_unitaire'],
                    'unite_achat_id' => $ligne['unite_achat_id'],
                ]);
            }

            return CatalogueFournisseur::with(['matierePremiere', 'uniteAchat'])
                ->where('fournisseur_id', $fournisseur->id)
                ->get();
        });

        return ApiResponse::updated(
            CatalogueFournisseurResource::collection($catalogue),
            'Catalogue du fournisseur mis à jour avec succès.'
        );
    }
}
