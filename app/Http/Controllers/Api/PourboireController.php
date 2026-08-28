<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Versement;
use App\Services\PourboireService;
use App\Support\SitePerimetre;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PourboireController extends Controller
{
    public function __construct(private readonly PourboireService $service) {}

    /**
     * Ce qui reste dû à chacun sur les pourboires encaissés au comptoir.
     *
     * Gardé par `versement.view` : lire ce qu'on doit au personnel relève du
     * même geste que voir sortir l'argent du tiroir.
     */
    public function soldes(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Versement::class);

        $sites = SitePerimetre::resoudre($request->user(), null);

        return ApiResponse::success(
            ['soldes' => $this->service->soldesParServeur($sites)],
            'Soldes de pourboires récupérés.'
        );
    }

    /**
     * Ce qu'un serveur voit de ses PROPRES pourboires.
     *
     * Gardé par `pourboire.suivre_les_siens`, volontairement absente de tous les
     * rôles par défaut : c'est à la direction de décider si l'équipe suit ses
     * cumuls, et elle attribue ou retire la permission quand elle veut, sans
     * qu'aucun code ne change.
     *
     * Aucun identifiant n'est accepté en paramètre : on ne lit que soi-même, et
     * le périmètre de sites de l'utilisateur borne le reste.
     */
    public function monSolde(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('pourboire.suivre_les_siens'), 403);

        $userId = $request->user()->id;
        $sites = SitePerimetre::resoudre($request->user(), null);

        return ApiResponse::success([
            'solde' => $this->service->soldePour($userId, $sites),
            'reversements' => $this->service->reversementsRecusPar($userId),
        ], 'Solde de pourboires récupéré.');
    }
}
