<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Depense\StoreDepenseRequest;
use App\Http\Resources\DepenseResource;
use App\Models\Budget;
use Illuminate\Http\JsonResponse;

class DepenseController extends Controller
{
    /**
     * Enregistre une charge hors stock saisie manuellement sur un budget.
     *
     * `type` est forcé à `charge_hors_stock` et `reception_id` reste null : ce
     * n'est jamais le client qui les fournit. Aucune contrainte de plafond — on
     * laisse dépasser le budget, on informe seulement (voir BudgetResource).
     */
    public function store(StoreDepenseRequest $request, Budget $budget): JsonResponse
    {
        $depense = $budget->depenses()->create([
            ...$request->validated(),
            'type' => 'charge_hors_stock',
            'reception_id' => null,
        ]);

        return ApiResponse::created(
            new DepenseResource($depense),
            'Charge hors stock enregistrée.'
        );
    }
}
