<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Exceptions\ReceptionException;
use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Reception\ConfirmerReceptionRequest;
use App\Http\Requests\Reception\StoreReceptionRequest;
use App\Http\Resources\ReceptionResource;
use App\Models\Reception;
use App\Services\ReceptionService;
use App\Support\SitePerimetre;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReceptionController extends Controller
{
    public function __construct(private readonly ReceptionService $service) {}

    /**
     * Liste les réceptions (filtres commande/site, pagination).
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Reception::class);

        $sites = SitePerimetre::resoudre(
            $request->user(),
            $request->filled('site_id') ? $request->integer('site_id') : null,
        );

        $receptions = Reception::query()
            ->with(['commande', 'site'])
            ->when(
                $request->filled('commande_fournisseur_id'),
                fn (Builder $query) => $query->where('commande_fournisseur_id', $request->integer('commande_fournisseur_id'))
            )
            ->when(
                $sites !== null,
                fn (Builder $query) => $query->whereIn('site_id', $sites)
            )
            ->orderByDesc('date_reception')
            ->paginate($request->integer('per_page', 15));

        return ApiResponse::success([
            'receptions' => ReceptionResource::collection($receptions->items()),
            'pagination' => [
                'total' => $receptions->total(),
                'par_page' => $receptions->perPage(),
                'page_courante' => $receptions->currentPage(),
                'derniere_page' => $receptions->lastPage(),
            ],
        ], 'Réceptions récupérées.');
    }

    /**
     * Crée une réception (entête) en attente de confirmation. Aucun mouvement de
     * stock n'est généré à ce stade.
     */
    public function store(StoreReceptionRequest $request): JsonResponse
    {
        $reception = new Reception($request->safe()->only([
            'commande_fournisseur_id',
            'site_id',
            'date_reception',
            'observations',
        ]));
        $reception->date_reception ??= now()->toDateString();
        $reception->user_id = $request->user()->id;
        $reception->save();

        return ApiResponse::created(
            new ReceptionResource($reception->load(['commande', 'site'])),
            'Réception créée. À confirmer pour alimenter le stock.'
        );
    }

    /**
     * Affiche une réception avec ses lignes.
     */
    public function show(Reception $reception): JsonResponse
    {
        $this->authorize('view', $reception);

        $reception->load(['commande', 'site', 'lignes.matierePremiere', 'lignes.unite']);

        return ApiResponse::success(
            new ReceptionResource($reception),
            'Réception récupérée.'
        );
    }

    /**
     * Confirme la réception : conversion, entrée de stock du site, dépense
     * budgétaire et transition de la commande. Tout ou rien.
     */
    public function confirmer(ConfirmerReceptionRequest $request, Reception $reception): JsonResponse
    {
        $reception = $this->service->confirmer(
            $reception,
            $request->validated()['lignes'],
            $request->user()->id,
        );

        return ApiResponse::updated(
            new ReceptionResource($reception),
            'Réception confirmée : stock et budget mis à jour.'
        );
    }

    /**
     * Supprime une réception — uniquement tant qu'elle n'est pas confirmée
     * (aucune ligne, donc aucun mouvement de stock ni dépense).
     */
    public function destroy(Reception $reception): JsonResponse
    {
        $this->authorize('delete', $reception);

        if ($reception->lignes()->exists()) {
            throw ReceptionException::dejaConfirmee($reception->id);
        }

        $reception->delete();

        return ApiResponse::deleted('Réception supprimée.');
    }
}
