<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\Statut;
use App\Enums\StatutCommandeClient;
use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\CommandeClient\StoreCommandeClientLigneRequest;
use App\Http\Requests\CommandeClient\StoreCommandeClientRequest;
use App\Http\Resources\CommandeClientResource;
use App\Http\Resources\LigneCommandeClientResource;
use App\Models\CommandeClient;
use App\Models\PointDeVente;
use App\Services\CommandeClientService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CommandeClientController extends Controller
{
    /** Relations chargées pour une commande complète. */
    private const RELATIONS = ['table', 'sessionCaisse', 'lignes.produit'];

    public function __construct(private readonly CommandeClientService $service) {}

    /**
     * Liste les commandes (filtres optionnels ?table_id et ?statut).
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', CommandeClient::class);

        $commandes = CommandeClient::query()
            ->with(self::RELATIONS)
            ->when(
                $request->filled('table_id'),
                fn (Builder $query) => $query->where('table_id', $request->integer('table_id'))
            )
            ->when(
                $request->filled('statut'),
                fn (Builder $query) => $query->where('statut', $request->string('statut'))
            )
            ->orderByDesc('id')
            ->get();

        return ApiResponse::success(
            ['commandes' => CommandeClientResource::collection($commandes)],
            'Commandes client récupérées.'
        );
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
     * Pas d'autorisation au-delà de auth:sanctum (écran cuisine connecté).
     */
    public function kds(PointDeVente $point_de_vente): JsonResponse
    {
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
