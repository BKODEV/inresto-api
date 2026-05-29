<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Budget\StoreBudgetRequest;
use App\Http\Requests\Budget\UpdateBudgetRequest;
use App\Http\Resources\BudgetResource;
use App\Models\Budget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BudgetController extends Controller
{
    /**
     * Liste les budgets (filtre par point de vente, pagination).
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Budget::class);

        $budgets = Budget::query()
            ->with('pointDeVente')
            ->when(
                $request->filled('point_de_vente_id'),
                fn (Builder $query) => $query->where('point_de_vente_id', $request->integer('point_de_vente_id'))
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
        $budget->load('pointDeVente');

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

        $budget->load('pointDeVente');

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
        $budget->load('pointDeVente');

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
}
