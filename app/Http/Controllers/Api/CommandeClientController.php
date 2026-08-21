<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\Statut;
use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\CommandeClient\EnvoyerEnCuisineRequest;
use App\Http\Requests\CommandeClient\IndexCommandeClientRequest;
use App\Http\Requests\CommandeClient\ServirRequest;
use App\Http\Requests\CommandeClient\StoreCommandeClientLigneRequest;
use App\Http\Requests\CommandeClient\StoreCommandeClientRequest;
use App\Http\Resources\CommandeClientResource;
use App\Http\Resources\LigneCommandeClientResource;
use App\Http\Resources\TableSalleResource;
use App\Models\CommandeClient;
use App\Models\LigneCommandeClient;
use App\Models\PointDeVente;
use App\Services\CommandeClientService;
use App\Support\SitePerimetre;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

class CommandeClientController extends Controller
{
    /** Relations chargées pour une commande complète. */
    /**
     * `user` = le serveur qui a ouvert la note. Chargé pour le poste de caisse :
     * quand un serveur vient verser, le caissier filtre sur lui pour solder ses
     * tables.
     */
    private const RELATIONS = ['table', 'sessionCaisse', 'lignes.produit', 'user'];

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
            // Alimente `peut_etre_liberee` sans requête par ligne.
            ->withCount(['lignes', 'paiements'])
            ->when(
                $filtres['avec_paiements'] ?? false,
                fn (Builder $query) => $query->with('paiements.modePaiement')
            )
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
            new CommandeClientResource(
                $commande->load(self::RELATIONS)->loadCount(['lignes', 'paiements'])
            ),
            'Commande client récupérée.'
        );
    }

    /**
     * Abandonne une commande ouverte que rien n'a jamais garnie.
     *
     * Gouverné par `commande_client.update` et non `.delete` : c'est le serveur
     * qui vient de taper la mauvaise table qui doit pouvoir défaire son geste,
     * or son rôle porte `update` (celle qui l'autorise déjà à ajouter et
     * retirer des lignes) et pas `delete`. Le garde-fou réel est dans le
     * service : une commande ayant porté quoi que ce soit est refusée en 409.
     */
    public function destroy(CommandeClient $commande): JsonResponse
    {
        $this->authorize('update', $commande);

        $this->service->liberer($commande);

        return ApiResponse::deleted('Table libérée.');
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
     * Envoie un tour en cuisine, panier compris.
     *
     * Geste unique de la prise de commande : les lignes du panier local partent
     * et sont expédiées en un seul appel, au lieu d'un aller-retour par plat.
     * Répétable — un repas se commande en plusieurs services.
     */
    public function envoyerEnCuisine(EnvoyerEnCuisineRequest $request, CommandeClient $commande): JsonResponse
    {
        $commande = $this->service->envoyerEnCuisine(
            $commande,
            $request->validated()['lignes'] ?? [],
            $request->user()->id,
        );

        return ApiResponse::updated(
            new CommandeClientResource($commande),
            'Tour envoyé en cuisine.'
        );
    }

    /**
     * Transition envoyee_en_cuisine → servie (avec déstockage).
     */
    public function servir(ServirRequest $request, CommandeClient $commande): JsonResponse
    {
        $commande = $this->service->servir(
            $commande,
            $request->user()->id,
            $request->validated()['lignes'] ?? [],
        );

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
     * KDS : file de production d'un point de vente, par tours.
     *
     * Interroge les LIGNES et non les commandes : une note vit plusieurs
     * services, et son statut ne dit pas ce qui reste à produire. Une ligne est
     * en production dès qu'elle est partie (`envoyee_le`) et tant qu'elle n'est
     * pas servie — les tours suivants d'une note déjà servie apparaissent donc
     * bien, ce que le filtre par statut de commande rendait impossible.
     *
     * Les lignes sont groupées par note puis par tour, du plus ancien au plus
     * récent : la cuisine produit dans l'ordre d'arrivée.
     */
    public function kds(PointDeVente $point_de_vente): JsonResponse
    {
        $this->authorize('viewAny', CommandeClient::class);

        $point_de_vente->loadMissing('site');
        SitePerimetre::resoudre(request()->user(), (int) $point_de_vente->site_id);

        $lignes = LigneCommandeClient::query()
            ->where('statut', Statut::Actif->value)
            ->whereNotNull('envoyee_le')
            ->whereNull('servie_le')
            ->whereHas(
                'commande.table',
                fn (Builder $query) => $query->where('point_de_vente_id', $point_de_vente->id)
            )
            ->with(['produit', 'commande.table'])
            ->orderBy('envoyee_le')
            ->orderBy('id')
            ->get();

        $tours = $lignes
            ->groupBy(fn (LigneCommandeClient $ligne): string => $ligne->commande_client_id.'|'.$ligne->envoyee_le?->toIso8601String())
            ->map(fn ($lignesDuTour): array => [
                'commande_id' => $lignesDuTour->first()->commande_client_id,
                'table' => new TableSalleResource($lignesDuTour->first()->commande->table),
                'envoyee_le' => $lignesDuTour->first()->envoyee_le?->toIso8601String(),
                'lignes' => LigneCommandeClientResource::collection($lignesDuTour->values()),
            ])
            ->values()
            ->all();

        return ApiResponse::success(
            ['tours' => $tours],
            'File KDS récupérée.'
        );
    }
}
