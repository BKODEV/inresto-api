<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\Statut;
use App\Enums\StatutCommandeClient;
use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\CommandeClient\IndexCommandeClientRequest;
use App\Http\Requests\CommandeClient\StoreCommandeClientLigneRequest;
use App\Http\Requests\CommandeClient\StoreCommandeClientRequest;
use App\Http\Resources\CommandeClientResource;
use App\Http\Resources\LigneCommandeClientResource;
use App\Models\CommandeClient;
use App\Models\PointDeVente;
use App\Services\CommandeClientService;
use App\Support\SitePerimetre;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

class CommandeClientController extends Controller
{
    /** Relations chargées pour une commande complète. */
    private const RELATIONS = ['table', 'sessionCaisse', 'lignes.produit'];

    public function __construct(private readonly CommandeClientService $service) {}

    /**
     * Liste paginée des commandes, du plus récent au plus ancien.
     *
     * Paginée et filtrée en base, jamais côté client : sans cela, l'écran de
     * prise de commande retéléchargeait tout l'historique du restaurant —
     * lignes et produits compris — toutes les vingt secondes, pour n'en garder
     * que les quelques commandes vivantes.
     *
     * Filtres : `point_de_vente_id`, `table_id`, `session_caisse_id`,
     * `statut[]`, et une plage `date_debut`/`date_fin`.
     *
     * Le périmètre de site s'applique via la table, seul chemin entre une
     * commande et son site (commande → table → point de vente → site).
     */
    public function index(IndexCommandeClientRequest $request): JsonResponse
    {
        $filtres = $request->validated();
        $sites = SitePerimetre::resoudre($request->user(), null);

        $commandes = CommandeClient::query()
            ->with(self::RELATIONS)
            ->when(
                $sites !== null,
                fn (Builder $query) => $query->whereHas(
                    'table.pointDeVente',
                    fn (Builder $pdv) => $pdv->whereIn('site_id', $sites)
                )
            )
            ->when(
                isset($filtres['point_de_vente_id']),
                fn (Builder $query) => $query->whereHas(
                    'table',
                    fn (Builder $table) => $table->where('point_de_vente_id', $filtres['point_de_vente_id'])
                )
            )
            ->when(
                isset($filtres['table_id']),
                fn (Builder $query) => $query->where('table_id', $filtres['table_id'])
            )
            ->when(
                isset($filtres['session_caisse_id']),
                fn (Builder $query) => $query->where('session_caisse_id', $filtres['session_caisse_id'])
            )
            ->when(
                isset($filtres['statut']),
                fn (Builder $query) => $query->whereIn('statut', $filtres['statut'])
            )
            ->when(
                isset($filtres['date_debut']),
                fn (Builder $query) => $query->where('date_heure', '>=', Carbon::parse($filtres['date_debut'])->startOfDay())
            )
            ->when(
                isset($filtres['date_fin']),
                fn (Builder $query) => $query->where('date_heure', '<=', Carbon::parse($filtres['date_fin'])->endOfDay())
            )
            ->orderByDesc('id')
            ->paginate($filtres['per_page'] ?? 50);

        return ApiResponse::success([
            'commandes' => CommandeClientResource::collection($commandes->items()),
            'pagination' => [
                'total' => $commandes->total(),
                'par_page' => $commandes->perPage(),
                'page_courante' => $commandes->currentPage(),
                'derniere_page' => $commandes->lastPage(),
            ],
        ], 'Commandes client récupérées.');
    }

    public function store(StoreCommandeClientRequest $request): JsonResponse
    {
        $commande = $this->service->creer($request->validated(), $request->user()->id);

        return ApiResponse::created(
            new CommandeClientResource($commande),
            'Commande client ouverte.'
        );
    }

    public function show(CommandeClient $commande): JsonResponse
    {
        $this->authorize('view', $commande);

        return ApiResponse::success(
            new CommandeClientResource($commande->load(self::RELATIONS)),
            'Commande client récupérée.'
        );
    }

    /**
     * Ajoute une ligne au prix de la carte active (commande ouverte uniquement).
     */
    public function ajouterLigne(StoreCommandeClientLigneRequest $request, CommandeClient $commande): JsonResponse
    {
        $ligne = $this->service->ajouterLigne($commande, $request->validated(), $request->user()->id);

        return ApiResponse::created(
            new LigneCommandeClientResource($ligne),
            'Ligne ajoutée à la commande.'
        );
    }

    /**
     * Transition ouverte → envoyee_en_cuisine.
     */
    public function envoyerEnCuisine(CommandeClient $commande): JsonResponse
    {
        $this->authorize('update', $commande);

        $commande = $this->service->envoyerEnCuisine($commande, request()->user()->id);

        return ApiResponse::updated(
            new CommandeClientResource($commande),
            'Commande envoyée en cuisine.'
        );
    }

    /**
     * Transition envoyee_en_cuisine → servie (avec déstockage).
     */
    public function servir(CommandeClient $commande): JsonResponse
    {
        $this->authorize('update', $commande);

        $commande = $this->service->servir($commande, request()->user()->id);

        return ApiResponse::updated(
            new CommandeClientResource($commande),
            'Commande servie.'
        );
    }

    /**
     * Clôture (encaissement) : transition servie → encaissee, table à débarrasser.
     */
    public function encaisser(CommandeClient $commande): JsonResponse
    {
        $this->authorize('encaisser', $commande);

        $commande = $this->service->encaisser($commande, request()->user()->id);

        return ApiResponse::updated(
            new CommandeClientResource($commande),
            'Commande encaissée.'
        );
    }

    /**
     * KDS : commandes envoyée_en_cuisine d'un PDV, avec leurs lignes actives.
     *
     * Gardé comme toute lecture de commandes, et borné au périmètre de site :
     * l'écran est monté au mur d'une cuisine, il n'a aucune raison d'exposer
     * la file d'un autre établissement. Le masquage côté front est un confort
     * d'affichage, pas une protection — la route reste appelable directement.
     */
    public function kds(PointDeVente $point_de_vente): JsonResponse
    {
        $this->authorize('viewAny', CommandeClient::class);

        $point_de_vente->loadMissing('site');
        SitePerimetre::resoudre(request()->user(), (int) $point_de_vente->site_id);

        $commandes = CommandeClient::query()
            ->where('statut', StatutCommandeClient::EnvoyeeEnCuisine->value)
            ->whereHas('table', fn (Builder $query) => $query->where('point_de_vente_id', $point_de_vente->id))
            ->with([
                'table',
                'lignes' => fn ($query) => $query->where('statut', Statut::Actif->value),
                'lignes.produit',
            ])
            ->orderBy('date_heure')
            ->get();

        return ApiResponse::success(
            ['commandes' => CommandeClientResource::collection($commandes)],
            'File KDS récupérée.'
        );
    }
}
