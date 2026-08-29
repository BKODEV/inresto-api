<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\SessionCuisine\FermerSessionCuisineRequest;
use App\Http\Requests\SessionCuisine\IndexSessionCuisineRequest;
use App\Http\Requests\SessionCuisine\StoreSessionCuisineRequest;
use App\Http\Resources\SessionCuisineResource;
use App\Models\SessionCuisine;
use App\Services\SessionCuisineService;
use App\Support\SitePerimetre;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

/**
 * Services de cuisine — le pendant brigade de la session de caisse.
 *
 * Indépendants d'elle à dessein : un point de vente porte plusieurs caisses
 * pour une seule cuisine, et la brigade travaille avant la première ouverture
 * de caisse comme après la dernière fermeture.
 */
class SessionCuisineController extends Controller
{
    /** Relations chargées pour un service complet. */
    private const RELATIONS = ['pointDeVente', 'ouvertPar', 'fermePar', 'brigade'];

    public function __construct(private readonly SessionCuisineService $service) {}

    public function index(IndexSessionCuisineRequest $request): JsonResponse
    {
        $filtres = $request->validated();
        $sites = SitePerimetre::resoudre($request->user(), null);

        $sessions = SessionCuisine::query()
            ->with(self::RELATIONS)
            // Alimente le volume produit sans une requête par ligne.
            ->withCount('lignes')
            ->when(
                $sites !== null,
                fn (Builder $query) => $query->whereHas(
                    'pointDeVente',
                    fn (Builder $pdv) => $pdv->whereIn('site_id', $sites)
                )
            )
            ->when(
                isset($filtres['point_de_vente_id']),
                fn (Builder $query) => $query->where('point_de_vente_id', $filtres['point_de_vente_id'])
            )
            ->when(
                isset($filtres['statut']),
                fn (Builder $query) => $query->where('statut', $filtres['statut'])
            )
            ->when(
                isset($filtres['date_debut']),
                fn (Builder $query) => $query->where('ouvert_le', '>=', Carbon::parse($filtres['date_debut'])->startOfDay())
            )
            ->when(
                isset($filtres['date_fin']),
                fn (Builder $query) => $query->where('ouvert_le', '<=', Carbon::parse($filtres['date_fin'])->endOfDay())
            )
            // Le service ouvert d'abord : c'est celui qu'on vient consulter.
            ->orderByRaw("CASE WHEN statut = 'ouverte' THEN 0 ELSE 1 END")
            ->orderByDesc('ouvert_le')
            ->orderByDesc('id')
            ->paginate($filtres['per_page'] ?? 50);

        return ApiResponse::success([
            'sessions' => SessionCuisineResource::collection($sessions->items()),
            'pagination' => [
                'total' => $sessions->total(),
                'par_page' => $sessions->perPage(),
                'page_courante' => $sessions->currentPage(),
                'derniere_page' => $sessions->lastPage(),
            ],
        ], 'Services de cuisine récupérés.');
    }

    public function show(SessionCuisine $session_cuisine): JsonResponse
    {
        $this->authorize('view', $session_cuisine);

        return ApiResponse::success(
            new SessionCuisineResource($session_cuisine->load(self::RELATIONS)->loadCount('lignes')),
            'Service de cuisine récupéré.'
        );
    }

    public function store(StoreSessionCuisineRequest $request): JsonResponse
    {
        $session = $this->service->ouvrir($request->validated(), $request->user()->id);

        return ApiResponse::created(
            new SessionCuisineResource($session),
            'Service de cuisine ouvert.'
        );
    }

    public function fermer(FermerSessionCuisineRequest $request, SessionCuisine $session_cuisine): JsonResponse
    {
        $session = $this->service->fermer($session_cuisine, $request->validated(), $request->user()->id);

        return ApiResponse::updated(
            new SessionCuisineResource($session),
            'Service de cuisine fermé.'
        );
    }

    /**
     * Ce que la brigade a produit : par produit, quantité et chiffre d'affaires.
     *
     * C'est la raison d'être du module — savoir quelle équipe a produit quoi, là
     * où les ventes ne disent que quel serveur a vendu.
     */
    public function production(SessionCuisine $session_cuisine): JsonResponse
    {
        $this->authorize('view', $session_cuisine);

        $session_cuisine->load(self::RELATIONS);

        return ApiResponse::success([
            'session' => new SessionCuisineResource($session_cuisine),
            'production' => $this->service->production($session_cuisine),
        ], 'Production du service récupérée.');
    }
}
