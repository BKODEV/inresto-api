<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\StatutTable;
use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\TableSalle\ChangerStatutRequest;
use App\Http\Requests\TableSalle\StoreTableSalleRequest;
use App\Http\Requests\TableSalle\UpdateTableSalleRequest;
use App\Http\Resources\TableSalleResource;
use App\Models\TableSalle;
use App\Services\TableSalleService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TableSalleController extends Controller
{
    public function __construct(private readonly TableSalleService $service) {}

    /**
     * Liste les tables (filtre optionnel ?point_de_vente_id).
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', TableSalle::class);

        $tables = TableSalle::query()
            ->with('pointDeVente')
            ->when(
                $request->filled('point_de_vente_id'),
                fn (Builder $query) => $query->where('point_de_vente_id', $request->integer('point_de_vente_id'))
            )
            ->orderBy('numero')
            ->get();

        return ApiResponse::success(
            ['tables' => TableSalleResource::collection($tables)],
            'Tables récupérées.'
        );
    }

    public function store(StoreTableSalleRequest $request): JsonResponse
    {
        $table = $this->service->creer($request->validated());

        return ApiResponse::created(
            new TableSalleResource($table),
            'Table enregistrée.'
        );
    }

    public function show(TableSalle $table): JsonResponse
    {
        $this->authorize('view', $table);

        return ApiResponse::success(
            new TableSalleResource($table->load('pointDeVente')),
            'Table récupérée.'
        );
    }

    public function update(UpdateTableSalleRequest $request, TableSalle $table): JsonResponse
    {
        $table = $this->service->modifier($table, $request->validated());

        return ApiResponse::updated(
            new TableSalleResource($table),
            'Table mise à jour.'
        );
    }

    public function destroy(TableSalle $table): JsonResponse
    {
        $this->authorize('delete', $table);

        $this->service->supprimer($table);

        return ApiResponse::deleted('Table supprimée.');
    }

    /**
     * Régénère le jeton public de la table : l'ancien lien et les QR déjà
     * imprimés deviennent caducs. Réservé aux profils qui peuvent modifier la
     * table (permission `table.update`).
     */
    public function regenererToken(TableSalle $table): JsonResponse
    {
        $this->authorize('update', $table);

        $table = $this->service->regenererToken($table);

        return ApiResponse::updated(
            new TableSalleResource($table),
            'Jeton public régénéré. Les anciens QR de cette table ne sont plus valides.'
        );
    }

    /**
     * Changement manuel de statut (ex. marquer « à débarrasser »).
     */
    public function changerStatut(ChangerStatutRequest $request, TableSalle $table): JsonResponse
    {
        $table = $this->service->changerStatut($table, $request->enum('statut', StatutTable::class));

        return ApiResponse::updated(
            new TableSalleResource($table),
            'Statut de la table mis à jour.'
        );
    }
}
