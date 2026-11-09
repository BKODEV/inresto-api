<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\BonCuisine\FiltreBonsCuisineRequest;
use App\Http\Resources\BonCuisineResource;
use App\Models\BonCuisine;
use App\Models\CommandeClient;
use App\Models\PointDeVente;
use App\Services\BonCuisineService;
use App\Services\PdfService;
use App\Support\SitePerimetre;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lecture et impression des bons de cuisine. Un bon naît de l'envoi en cuisine
 * ou de l'annulation d'une ligne, jamais d'un appel direct, et son contenu ne se
 * modifie pas une fois émis : seul son suivi d'impression bouge.
 *
 * Gouverné par la lecture des commandes : le bon n'est qu'une vue de la note
 * découpée par poste.
 */
class BonCuisineController extends Controller
{
    private const RELATIONS = ['lignes', 'user', 'commande.user'];

    public function __construct(
        private readonly BonCuisineService $service,
        private readonly PdfService $pdf,
    ) {}

    /**
     * Les bons d'un point de vente — la file de la caisse quand `a_imprimer`
     * est demandé : ce que les serveurs ont envoyé et que personne n'a encore
     * sorti. Du plus ancien au plus récent : la cuisine produit dans l'ordre.
     */
    public function pointDeVente(FiltreBonsCuisineRequest $request, PointDeVente $point_de_vente): JsonResponse
    {
        $this->verifierPerimetre($request, (int) $point_de_vente->id);
        $filtres = $request->validated();

        $bons = BonCuisine::query()
            ->with(self::RELATIONS)
            ->where('point_de_vente_id', $point_de_vente->id)
            ->when(
                $filtres['a_imprimer'] ?? false,
                fn ($query) => $query->enAttente(),
                fn ($query) => $query->whereDate('jour', $filtres['jour'] ?? Carbon::today()->toDateString()),
            )
            ->orderBy('emis_le')
            ->orderBy('numero')
            ->get();

        return ApiResponse::success(
            BonCuisineResource::collection($bons),
            'Bons de cuisine récupérés.'
        );
    }

    /**
     * Imprime des bons du point de vente (80mm, un par page).
     *
     * Sans `bons[]`, sort toute la file en attente et la vide — le bouton
     * « imprimer tout » de la caisse. Avec, sort ces bons-là : ceux d'une note,
     * ou une réimpression, marquée DUPLICATA.
     */
    public function imprimerLot(FiltreBonsCuisineRequest $request, PointDeVente $point_de_vente): Response
    {
        $this->verifierPerimetre($request, (int) $point_de_vente->id);
        $demandes = $request->validated()['bons'] ?? null;

        if ($demandes !== null) {
            $etrangers = collect($demandes)->diff(
                BonCuisine::query()
                    ->where('point_de_vente_id', $point_de_vente->id)
                    ->whereKey($demandes)
                    ->pluck('id')
            );

            // Refusé plutôt qu'ignoré : un bon demandé qui ne sort pas serait un
            // plat que personne ne cuisine.
            if ($etrangers->isNotEmpty()) {
                return ApiResponse::unprocessableEntity(
                    ['bons' => ['Ces bons n\'appartiennent pas à ce point de vente : '.$etrangers->implode(', ').'.']]
                );
            }
        }

        $resultat = $demandes === null
            ? $this->service->prendreAImprimer((int) $point_de_vente->id)
            : $this->service->imprimer($demandes);

        if ($resultat['bons']->isEmpty()) {
            return ApiResponse::custom(409, 'error', 'Aucun bon à imprimer : la file est vide, ou ses plats ont été servis ou annulés entre-temps.');
        }

        return $this->pdf->bonsCuisine(
            $resultat['bons'],
            $resultat['duplicatas'],
            "bons-cuisine-{$point_de_vente->id}.pdf",
        );
    }

    /** Un bon seul : en DUPLICATA s'il est déjà sorti. */
    public function imprimer(Request $request, BonCuisine $bon_cuisine): Response
    {
        $bon_cuisine->loadMissing('commande');
        $this->authorize('view', $bon_cuisine->commande);
        $this->verifierPerimetre($request, (int) $bon_cuisine->point_de_vente_id);

        $resultat = $this->service->imprimer([$bon_cuisine->id]);

        // Servi ou annulé avant d'avoir été sorti : rien à mettre sur le papier.
        if ($resultat['bons']->isEmpty()) {
            return ApiResponse::custom(409, 'error', 'Ce bon est sans objet : ses plats ont été servis ou annulés avant son impression.');
        }

        return $this->pdf->bonsCuisine(
            $resultat['bons'],
            $resultat['duplicatas'],
            "bon-cuisine-{$bon_cuisine->numero}.pdf",
        );
    }

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
