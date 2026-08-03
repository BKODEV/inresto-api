<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Resources\StockResource;
use App\Models\MatierePremiere;
use App\Models\Stock;
use App\Support\SitePerimetre;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Lecture opérationnelle du stock par site (photo instantanée : quantité
 * courante, PMP, valeur, alerte de seuil).
 *
 * Distinct des rapports `reporting/stock/*` : ici pas d'agrégation ni de
 * période, une ligne par couple (matière, site), paginée — c'est l'écran de
 * cuisine, gardé par `stock.view` et non par une permission de reporting.
 *
 * Lecture seule : le stock est dérivé des mouvements et n'est écrit que par
 * le StockService.
 */
class StockController extends Controller
{
    /**
     * Liste l'état du stock (filtres optionnels ?site_id et ?sous_seuil, pagination).
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Stock::class);

        $sites = SitePerimetre::resoudre(
            $request->user(),
            $request->filled('site_id') ? $request->integer('site_id') : null,
        );

        $stocks = Stock::query()
            // `matiere` est indispensable : la resource lit le seuil d'alerte
            // pour chaque ligne (sinon une requête par ligne).
            ->with(['matiere.unite', 'site'])
            ->when(
                $sites !== null,
                fn (Builder $query) => $query->whereIn('site_id', $sites)
            )
            ->when(
                $request->boolean('sous_seuil'),
                fn (Builder $query) => $query->whereHas(
                    'matiere',
                    fn (Builder $matiere) => $matiere
                        ->where('seuil_alerte_stock', '>', 0)
                        ->whereColumn('stocks.stock_courant', '<=', 'matieres_premieres.seuil_alerte_stock')
                )
            )
            ->orderBy(
                MatierePremiere::query()
                    ->select('libelle')
                    ->whereColumn('matieres_premieres.id', 'stocks.matiere_premiere_id')
            )
            ->paginate($request->integer('per_page', 50));

        return ApiResponse::success([
            'stocks' => StockResource::collection($stocks->items()),
            // Valeur totale de la page courante uniquement (le total global du
            // parc relève du reporting, pas de cet écran opérationnel).
            'total_valorise' => round(
                collect($stocks->items())->sum(fn (Stock $stock): float => $stock->valeur()),
                2,
            ),
            'pagination' => [
                'total' => $stocks->total(),
                'par_page' => $stocks->perPage(),
                'page_courante' => $stocks->currentPage(),
                'derniere_page' => $stocks->lastPage(),
            ],
        ], 'État du stock récupéré.');
    }
}
