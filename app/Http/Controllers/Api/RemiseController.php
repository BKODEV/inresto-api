<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Remise\IndexRemiseRequest;
use App\Http\Requests\Remise\StoreRemiseRequest;
use App\Http\Resources\RemiseResource;
use App\Models\CommandeClient;
use App\Models\Remise;
use App\Services\RemiseService;
use App\Support\SitePerimetre;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

class RemiseController extends Controller
{
    public function __construct(private readonly RemiseService $service) {}

    /**
     * Liste paginée des remises accordées, de la plus récente à la plus ancienne.
     *
     * Le journal d'activité montre déjà une remise au fil du service ; il
     * plafonne chaque source et mélange les natures d'événement. Cette liste
     * répond à l'autre question, celle qu'on pose en fin de mois : combien a-t-on
     * remis, par qui, sur quels motifs.
     *
     * Strictement en lecture — une remise accordée ne se corrige pas, elle
     * s'annule avec la note.
     */
    public function index(IndexRemiseRequest $request): JsonResponse
    {
        $filtres = $request->validated();

        // `validated()` restitue la valeur brute de la query string : le cast
        // est nécessaire, `resoudre()` attendant un entier.
        $sites = SitePerimetre::resoudre(
            $request->user(),
            isset($filtres['site_id']) ? (int) $filtres['site_id'] : null,
        );

        $pointDeVenteId = isset($filtres['point_de_vente_id'])
            ? (int) $filtres['point_de_vente_id']
            : null;

        $remises = Remise::query()
            ->with(['user', 'commande.table'])
            // Une remise n'a pas de site à elle : elle le tient de sa commande,
            // par la table puis le point de vente — seul chemin existant.
            ->when(
                $sites !== null || $pointDeVenteId !== null,
                fn (Builder $query) => $query->whereHas(
                    'commande.table',
                    function (Builder $table) use ($sites, $pointDeVenteId): void {
                        $table
                            ->when($pointDeVenteId !== null, fn (Builder $q) => $q->where('point_de_vente_id', $pointDeVenteId))
                            ->when($sites !== null, fn (Builder $q) => $q->whereHas(
                                'pointDeVente',
                                fn (Builder $pdv) => $pdv->whereIn('site_id', $sites)
                            ));
                    }
                )
            )
            ->when(
                isset($filtres['commande_client_id']),
                fn (Builder $query) => $query->where('commande_client_id', $filtres['commande_client_id'])
            )
            ->when(
                isset($filtres['type']),
                fn (Builder $query) => $query->where('type', $filtres['type'])
            )
            // `date_heure` est un timestamp : bornes en date-heure et non en
            // chaîne « Y-m-d », sinon la borne haute exclut le dernier jour.
            ->when(
                isset($filtres['date_debut']),
                fn (Builder $query) => $query->where('date_heure', '>=', Carbon::parse($filtres['date_debut'])->startOfDay())
            )
            ->when(
                isset($filtres['date_fin']),
                fn (Builder $query) => $query->where('date_heure', '<=', Carbon::parse($filtres['date_fin'])->endOfDay())
            )
            ->orderByDesc('date_heure')
            // Départage les remises d'un même instant.
            ->orderByDesc('id')
            ->paginate($filtres['per_page'] ?? 50);

        return ApiResponse::success([
            'remises' => RemiseResource::collection($remises->items()),
            'pagination' => [
                'total' => $remises->total(),
                'par_page' => $remises->perPage(),
                'page_courante' => $remises->currentPage(),
                'derniere_page' => $remises->lastPage(),
            ],
        ], 'Remises récupérées.');
    }

    /**
     * Applique une remise à une commande (ouverte ou servie). Au-delà du seuil,
     * la permission remise.autoriser_au_dela_seuil est requise.
     */
    public function store(StoreRemiseRequest $request, CommandeClient $commande): JsonResponse
    {
        $remise = $this->service->appliquer($commande, $request->validated(), $request->user()->id);

        return ApiResponse::created(
            new RemiseResource($remise),
            'Remise appliquée.'
        );
    }
}
