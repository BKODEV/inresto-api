<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Resources\BonCuisineResource;
use App\Models\BonCuisine;
use App\Models\CommandeClient;
use App\Models\PointDeVente;
use App\Support\SitePerimetre;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Lecture des bons de cuisine. Aucune écriture ici : un bon naît de l'envoi en
 * cuisine ou de l'annulation d'une ligne, jamais d'un appel direct, et ne se
 * modifie pas une fois émis.
 *
 * Gouverné par la lecture des commandes : le bon n'est qu'une vue de la note
 * découpée par poste.
 */
class BonCuisineController extends Controller
{
    private const RELATIONS = ['lignes', 'user', 'commande.user'];

    /** Les bons d'une note, dans l'ordre d'émission. */
    public function index(Request $request, CommandeClient $commande): JsonResponse
    {
        $this->authorize('view', $commande);
        $this->verifierPerimetre($request, (int) $commande->table()->value('point_de_vente_id'));

        $bons = BonCuisine::query()
            ->with(self::RELATIONS)
            ->where('commande_client_id', $commande->id)
            ->orderBy('emis_le')
            ->orderBy('id')
            ->get();

        return ApiResponse::success(
            BonCuisineResource::collection($bons),
            'Bons de cuisine récupérés.'
        );
    }

    public function show(Request $request, BonCuisine $bon_cuisine): JsonResponse
    {
        $bon_cuisine->loadMissing('commande');
        $this->authorize('view', $bon_cuisine->commande);
        $this->verifierPerimetre($request, (int) $bon_cuisine->point_de_vente_id);

        return ApiResponse::success(
            new BonCuisineResource($bon_cuisine->load(self::RELATIONS)),
            'Bon de cuisine récupéré.'
        );
    }

    /** Un bon porte la table, les plats et le serveur : il reste dans le périmètre de son site. */
    private function verifierPerimetre(Request $request, int $pointDeVenteId): void
    {
        $siteId = PointDeVente::query()->whereKey($pointDeVenteId)->value('site_id');

        SitePerimetre::resoudre($request->user(), $siteId === null ? null : (int) $siteId);
    }
}
