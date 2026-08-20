<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\StatutTable;
use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\TableSalle\ChangerStatutRequest;
use App\Http\Requests\TableSalle\StoreTableSalleLotRequest;
use App\Http\Requests\TableSalle\StoreTableSalleRequest;
use App\Http\Requests\TableSalle\UpdateTableSalleRequest;
use App\Http\Resources\TableSalleResource;
use App\Models\TableSalle;
use App\Services\TableSalleService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TableSalleController extends Controller
{
    public function __construct(private readonly TableSalleService $service) {}

    /**
     * Liste les tables (filtres optionnels ?point_de_vente_id et ?archivees).
     *
     * Les tables archivées sont exclues par défaut : elles ne font plus partie
     * de la salle. `?archivees=1` les rajoute, pour l'écran qui permet de les
     * réactiver.
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
            ->unless(
                $request->boolean('archivees'),
                fn (Builder $query) => $query->whereNull('archivee_le')
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

    /**
     * Crée une série de tables d'un coup — saisir un plan de salle de trente
     * tables une par une est impraticable, en particulier à l'ouverture.
     *
     * Tout ou rien : un seul numéro en défaut (déjà pris, en doublon dans la
     * requête) fait échouer le lot entier en 422. Une création partielle
     * laisserait l'utilisateur deviner ce qui est passé, et rejouer le lot
     * corrigé buterait sur les tables déjà créées.
     */
    public function storeLot(StoreTableSalleLotRequest $request): JsonResponse
    {
        $valide = $request->validated();

        $ids = DB::transaction(fn (): array => $this->service->creerEnLot(
            (int) $valide['point_de_vente_id'],
            $valide['lignes'],
        ));

        $creees = TableSalle::query()
            ->whereIn('id', $ids)
            ->with('pointDeVente')
            ->orderBy('numero')
            ->get();

        return ApiResponse::created(
            TableSalleResource::collection($creees),
            $creees->count().' table(s) créée(s).'
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
     * Retire la table du service. Gouverné par `table.delete` : c'est le même
     * geste métier que la suppression, pour les tables qu'on ne peut plus
     * supprimer parce qu'elles ont déjà servi.
     */
    public function archiver(TableSalle $table): JsonResponse
    {
        $this->authorize('delete', $table);

        $table = $this->service->archiver($table);

        return ApiResponse::updated(
            new TableSalleResource($table),
            'Table archivée. Son QR ne sert plus de menu et elle sort du plan de salle.'
        );
    }

    /**
     * Remet en service une table archivée. Son QR déjà imprimé refonctionne.
     */
    public function reactiver(TableSalle $table): JsonResponse
    {
        $this->authorize('delete', $table);

        $table = $this->service->reactiver($table);

        return ApiResponse::updated(
            new TableSalleResource($table),
            'Table réactivée.'
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
