<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Annulation\AnnulerCommandeRequest;
use App\Http\Requests\Annulation\AnnulerLigneRequest;
use App\Http\Requests\Annulation\IndexAnnulationRequest;
use App\Http\Resources\AnnulationResource;
use App\Http\Resources\CommandeClientResource;
use App\Models\Annulation;
use App\Models\CommandeClient;
use App\Models\LigneCommandeClient;
use App\Services\AnnulationService;
use App\Support\SitePerimetre;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

class AnnulationController extends Controller
{
    public function __construct(private readonly AnnulationService $service) {}

    /**
     * Liste paginée des annulations, de la plus récente à la plus ancienne.
     *
     * Le journal d'activité en montre déjà au fil du service, plafonnées et
     * mêlées aux autres gestes. Cette liste répond à la question de contrôle :
     * ce qui a été annulé APRÈS service est-il reparti en stock ou passé en
     * perte, et sur quels motifs.
     *
     * Strictement en lecture : une annulation est immuable.
     */
    public function index(IndexAnnulationRequest $request): JsonResponse
    {
        $filtres = $request->validated();

        $sites = SitePerimetre::resoudre(
            $request->user(),
            isset($filtres['site_id']) ? (int) $filtres['site_id'] : null,
        );

        $pointDeVenteId = isset($filtres['point_de_vente_id'])
            ? (int) $filtres['point_de_vente_id']
            : null;

        $annulations = Annulation::query()
            ->with(['user', 'annulable'])
            // Le périmètre se lit à travers la relation polymorphe : une
            // commande porte sa table, une LIGNE ne la porte que par sa
            // commande. D'où deux chemins et non un.
            ->when(
                $sites !== null || $pointDeVenteId !== null,
                fn (Builder $query) => $query->whereHasMorph(
                    'annulable',
                    [CommandeClient::class, LigneCommandeClient::class],
                    function (Builder $morph, string $type) use ($sites, $pointDeVenteId): void {
                        $chemin = $type === LigneCommandeClient::class ? 'commande.table' : 'table';
                        $morph->whereHas($chemin, function (Builder $table) use ($sites, $pointDeVenteId): void {
                            $table
                                ->when($pointDeVenteId !== null, fn (Builder $q) => $q->where('point_de_vente_id', $pointDeVenteId))
                                ->when($sites !== null, fn (Builder $q) => $q->whereHas(
                                    'pointDeVente',
                                    fn (Builder $pdv) => $pdv->whereIn('site_id', $sites)
                                ));
                        });
                    }
                )
            )
            ->when(
                isset($filtres['annulable_type']),
                fn (Builder $query) => $query->where('annulable_type', $filtres['annulable_type'])
            )
            ->when(
                isset($filtres['sort']),
                fn (Builder $query) => $query->where('sort', $filtres['sort'])
            )
            ->when(
                isset($filtres['date_debut']),
                fn (Builder $query) => $query->where('date_heure', '>=', Carbon::parse($filtres['date_debut'])->startOfDay())
            )
            ->when(
                isset($filtres['date_fin']),
                fn (Builder $query) => $query->where('date_heure', '<=', Carbon::parse($filtres['date_fin'])->endOfDay())
            )
            ->orderByDesc('date_heure')
            ->orderByDesc('id')
            ->paginate($filtres['per_page'] ?? 50);

        return ApiResponse::success([
            'annulations' => AnnulationResource::collection($annulations->items()),
            'pagination' => [
                'total' => $annulations->total(),
                'par_page' => $annulations->perPage(),
                'page_courante' => $annulations->currentPage(),
                'derniere_page' => $annulations->lastPage(),
            ],
        ], 'Annulations récupérées.');
    }

    /**
     * Annule (soft) une ligne de commande. Si la commande était servie, `sort`
     * pilote le devenir du stock déjà déchargé (remise_en_stock | perte).
     */
    public function annulerLigne(AnnulerLigneRequest $request, CommandeClient $commande, LigneCommandeClient $ligne): JsonResponse
    {
        abort_if($ligne->commande_client_id !== $commande->id, 404);

        $annulation = $this->service->annulerLigne($commande, $ligne, $request->validated(), $request->user()->id);

        // `null` = la ligne était encore au brouillon : retirée sans trace, il
        // n'y a pas d'annulation à renvoyer.
        if ($annulation === null) {
            return ApiResponse::deleted('Ligne retirée.');
        }

        return ApiResponse::success(
            new AnnulationResource($annulation),
            'Ligne annulée.'
        );
    }

    /**
     * Annule une commande entière (ouverte ou envoyée en cuisine). Libère la table.
     */
    public function annulerCommande(AnnulerCommandeRequest $request, CommandeClient $commande): JsonResponse
    {
        $this->service->annulerCommande($commande, $request->validated(), $request->user()->id);

        return ApiResponse::success(
            new CommandeClientResource($commande->load(['table', 'sessionCaisse', 'lignes.produit'])),
            'Commande annulée.'
        );
    }
}
