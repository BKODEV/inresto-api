<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Stock\IndexMouvementStockRequest;
use App\Http\Resources\MouvementStockResource;
use App\Models\MouvementStock;
use App\Support\SitePerimetre;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

/**
 * Journal des mouvements de stock : entrées de réception, sorties (vente,
 * perte, casse, offert, repas personnel) et corrections d'inventaire, ligne à
 * ligne, avec leur origine.
 *
 * Complète les rapports `reporting/stock/consommation`, qui n'agrègent que les
 * sorties par matière et par type, sans date, ni auteur, ni pièce d'origine.
 *
 * Strictement en lecture : un mouvement est immuable.
 */
class MouvementStockController extends Controller
{
    /**
     * Liste paginée, du plus récent au plus ancien.
     */
    public function index(IndexMouvementStockRequest $request): JsonResponse
    {
        $filtres = $request->validated();

        // `validated()` restitue la valeur brute de la query string : un cast
        // explicite est nécessaire, `resoudre()` attendant un entier.
        $sites = SitePerimetre::resoudre(
            $request->user(),
            isset($filtres['site_id']) ? (int) $filtres['site_id'] : null,
        );

        $mouvements = MouvementStock::query()
            ->with(['matiere.unite', 'site', 'user'])
            ->when(
                $sites !== null,
                fn (Builder $query) => $query->whereIn('site_id', $sites)
            )
            ->when(
                isset($filtres['matiere_premiere_id']),
                fn (Builder $query) => $query->where('matiere_premiere_id', $filtres['matiere_premiere_id'])
            )
            ->when(
                isset($filtres['type']),
                fn (Builder $query) => $query->where('type', $filtres['type'])
            )
            // `date_mouvement` est un timestamp : on borne en date-heure et non
            // en chaîne « Y-m-d », sinon la borne haute exclurait tout ce qui
            // s'est passé après minuit le dernier jour de la période.
            ->when(
                isset($filtres['date_debut']),
                fn (Builder $query) => $query->where('date_mouvement', '>=', Carbon::parse($filtres['date_debut'])->startOfDay())
            )
            ->when(
                isset($filtres['date_fin']),
                fn (Builder $query) => $query->where('date_mouvement', '<=', Carbon::parse($filtres['date_fin'])->endOfDay())
            )
            ->orderByDesc('date_mouvement')
            // Départage les mouvements d'un même instant (une confirmation de
            // réception en crée plusieurs à la même seconde).
            ->orderByDesc('id')
            ->paginate($filtres['per_page'] ?? 50);

        return ApiResponse::success([
            'mouvements' => MouvementStockResource::collection($mouvements->items()),
            'pagination' => [
                'total' => $mouvements->total(),
                'par_page' => $mouvements->perPage(),
                'page_courante' => $mouvements->currentPage(),
                'derniere_page' => $mouvements->lastPage(),
            ],
        ], 'Mouvements de stock récupérés.');
    }
}
