<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Budget\StoreBudgetRequest;
use App\Http\Requests\Budget\UpdateBudgetRequest;
use App\Http\Resources\BudgetResource;
use App\Models\Budget;
use App\Models\Site;
use App\Support\SitePerimetre;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class BudgetController extends Controller
{
    /**
     * Liste les budgets (filtre par site, pagination).
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Budget::class);

        $sites = SitePerimetre::resoudre(
            $request->user(),
            $request->filled('site_id') ? $request->integer('site_id') : null,
        );

        $budgets = Budget::query()
            ->with('site')
            ->withSum('depenses', 'montant')
            ->when(
                $sites !== null,
                fn (Builder $query) => $query->whereIn('site_id', $sites)
            )
            ->orderByDesc('mois')
            ->paginate($request->integer('per_page', 15));

        return ApiResponse::success([
            'budgets' => BudgetResource::collection($budgets->items()),
            'pagination' => [
                'total' => $budgets->total(),
                'par_page' => $budgets->perPage(),
                'page_courante' => $budgets->currentPage(),
                'derniere_page' => $budgets->lastPage(),
            ],
        ], 'Budgets récupérés.');
    }

    /**
     * Crée un nouveau budget.
     */
    public function store(StoreBudgetRequest $request): JsonResponse
    {
        $budget = Budget::create($request->validated());
        $budget->load('site');

        return ApiResponse::created(
            new BudgetResource($budget),
            'Budget créé avec succès.'
        );
    }

    /**
     * Affiche un budget.
     */
    public function show(Budget $budget): JsonResponse
    {
        $this->authorize('view', $budget);

        $budget->load('site');

        return ApiResponse::success(
            new BudgetResource($budget),
            'Budget récupéré.'
        );
    }

    /**
     * Met à jour un budget.
     */
    public function update(UpdateBudgetRequest $request, Budget $budget): JsonResponse
    {
        $budget->update($request->validated());
        $budget->load('site');

        return ApiResponse::updated(
            new BudgetResource($budget),
            'Budget mis à jour avec succès.'
        );
    }

    /**
     * Supprime un budget.
     */
    public function destroy(Budget $budget): JsonResponse
    {
        $this->authorize('delete', $budget);

        $budget->delete();

        return ApiResponse::deleted('Budget supprimé avec succès.');
    }

    /**
     * Liste les budgets d'un site, chacun avec ses champs de consommation
     * calculés. Pas de pagination forcée ; support optionnel de ?per_page.
     */
    public function indexForSite(Request $request, Site $site): JsonResponse
    {
        $this->authorize('viewAny', Budget::class);
        SitePerimetre::resoudre($request->user(), $site->id);

        $query = $site->budgets()
            ->withSum('depenses', 'montant')
            ->orderByDesc('mois');

        if ($request->filled('per_page')) {
            $budgets = $query->paginate($request->integer('per_page'));

            return ApiResponse::success([
                'budgets' => BudgetResource::collection($budgets->items()),
                'pagination' => [
                    'total' => $budgets->total(),
                    'par_page' => $budgets->perPage(),
                    'page_courante' => $budgets->currentPage(),
                    'derniere_page' => $budgets->lastPage(),
                ],
            ], 'Budgets du site récupérés.');
        }

        return ApiResponse::success(
            ['budgets' => BudgetResource::collection($query->get())],
            'Budgets du site récupérés.'
        );
    }

    /**
     * Résumé de consommation d'un budget de site pour un mois donné (YYYY-MM).
     * 404 si aucun budget pour ce couple site/mois.
     */
    public function showForSiteMonth(Site $site, string $mois): JsonResponse
    {
        $this->authorize('viewAny', Budget::class);
        SitePerimetre::resoudre(request()->user(), $site->id);

        $premierDuMois = Carbon::createFromFormat('Y-m-d', $mois.'-01')->startOfMonth();

        $budget = $site->budgets()
            ->whereDate('mois', $premierDuMois->toDateString())
            ->with(['site', 'depenses'])
            ->first();

        if ($budget === null) {
            throw new NotFoundHttpException("Aucun budget pour le site #{$site->id} sur le mois {$mois}.");
        }

        return ApiResponse::success(
            new BudgetResource($budget),
            'Budget récupéré.'
        );
    }
}
