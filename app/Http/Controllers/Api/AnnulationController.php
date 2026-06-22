<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Annulation\AnnulerCommandeRequest;
use App\Http\Requests\Annulation\AnnulerLigneRequest;
use App\Http\Resources\AnnulationResource;
use App\Http\Resources\CommandeClientResource;
use App\Models\CommandeClient;
use App\Models\LigneCommandeClient;
use App\Services\AnnulationService;
use Illuminate\Http\JsonResponse;

class AnnulationController extends Controller
{
    public function __construct(private readonly AnnulationService $service) {}

    /**
     * Annule (soft) une ligne de commande. Si la commande était servie, `sort`
     * pilote le devenir du stock déjà déchargé (remise_en_stock | perte).
     */
    public function annulerLigne(AnnulerLigneRequest $request, CommandeClient $commande, LigneCommandeClient $ligne): JsonResponse
    {
        abort_if($ligne->commande_client_id !== $commande->id, 404);

        $annulation = $this->service->annulerLigne($commande, $ligne, $request->validated(), $request->user()->id);

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
