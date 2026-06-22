<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\PointDeVente\StorePointDeVenteRequest;
use App\Http\Requests\PointDeVente\UpdatePointDeVenteRequest;
use App\Http\Resources\PointDeVenteResource;
use App\Models\PointDeVente;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class PointDeVenteController extends Controller
{
    /**
     * Liste tous les points de vente.
     */
    public function index(): JsonResponse
    {
        $this->authorize('viewAny', PointDeVente::class);

        return ApiResponse::success(
            PointDeVenteResource::collection(PointDeVente::all()),
            'Points de vente récupérés.'
        );
    }

    /**
     * Crée un nouveau point de vente, doté d'une caisse principale par défaut.
     * Le couple (PDV + caisse) est créé de façon atomique : un PDV ne naît jamais
     * sans caisse. L'utilisateur reste libre ensuite d'en ajouter, modifier ou
     * supprimer (voir CaisseController). Auto-création réservée à cette voie HTTP
     * pour ne pas polluer les factories/seeders.
     */
    public function store(StorePointDeVenteRequest $request): JsonResponse
    {
        $pointDeVente = DB::transaction(function () use ($request) {
            $pointDeVente = PointDeVente::create($request->validated());
            $pointDeVente->caisses()->create(['libelle' => 'Caisse principale']);

            return $pointDeVente;
        });

        $pointDeVente->loadCount('caisses');

        return ApiResponse::created(
            new PointDeVenteResource($pointDeVente),
            'Point de vente créé avec succès.'
        );
    }

    /**
     * Affiche un point de vente.
     */
    public function show(PointDeVente $pointDeVente): JsonResponse
    {
        $this->authorize('view', $pointDeVente);

        // Détail du PDV : on embarque ses caisses (avec leur nombre de sessions,
        // qui détermine si une caisse est supprimable) + le site parent.
        $pointDeVente->load([
            'site',
            'caisses' => fn ($query) => $query->withCount('sessions')->orderBy('libelle'),
        ]);
        $pointDeVente->loadCount('caisses');

        return ApiResponse::success(
            new PointDeVenteResource($pointDeVente),
            'Point de vente récupéré.'
        );
    }

    /**
     * Met à jour un point de vente.
     */
    public function update(UpdatePointDeVenteRequest $request, PointDeVente $pointDeVente): JsonResponse
    {
        $pointDeVente->update($request->validated());

        return ApiResponse::updated(
            new PointDeVenteResource($pointDeVente),
            'Point de vente mis à jour avec succès.'
        );
    }

    /**
     * Supprime un point de vente.
     */
    public function destroy(PointDeVente $pointDeVente): JsonResponse
    {
        $this->authorize('delete', $pointDeVente);

        $pointDeVente->delete();

        return ApiResponse::deleted('Point de vente supprimé avec succès.');
    }
}
