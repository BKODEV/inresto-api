<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\Statut;
use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Site\StoreSiteRequest;
use App\Http\Requests\Site\UpdateSiteRequest;
use App\Http\Resources\SiteResource;
use App\Models\Site;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SiteController extends Controller
{
    /**
     * Liste tous les sites (gestion, référentiel des Paramètres).
     */
    public function index(): JsonResponse
    {
        $this->authorize('viewAny', Site::class);

        return ApiResponse::success(
            ['sites' => SiteResource::collection(Site::orderBy('nom')->get())],
            'Sites récupérés.'
        );
    }

    /**
     * Périmètre métier de l'utilisateur connecté : ses sites actifs (principal
     * en tête). Alimente le sélecteur de site des écrans rattachés à un site.
     * Pas de permission dédiée (périmètre = ses propres sites).
     */
    public function perimetre(Request $request): JsonResponse
    {
        $sites = $request->user()
            ->sites()
            ->where('statut', Statut::Actif->value)
            ->orderByDesc('user_site.est_principal')
            ->orderBy('nom')
            ->get();

        return ApiResponse::success(
            ['sites' => SiteResource::collection($sites)],
            'Périmètre utilisateur récupéré.'
        );
    }

    public function store(StoreSiteRequest $request): JsonResponse
    {
        $site = Site::create($request->validated());

        return ApiResponse::created(new SiteResource($site), 'Site créé avec succès.');
    }

    public function show(Site $site): JsonResponse
    {
        $this->authorize('view', $site);

        return ApiResponse::success(new SiteResource($site), 'Site récupéré.');
    }

    public function update(UpdateSiteRequest $request, Site $site): JsonResponse
    {
        $site->update($request->validated());

        return ApiResponse::updated(new SiteResource($site), 'Site mis à jour avec succès.');
    }

    public function destroy(Site $site): JsonResponse
    {
        $this->authorize('delete', $site);

        // Un site portant des opérations (points de vente, budgets, stocks) ne
        // peut être supprimé : on protège l'intégrité plutôt que de casser les FK.
        if ($site->pointsDeVente()->exists() || $site->budgets()->exists() || $site->stocks()->exists()) {
            return ApiResponse::custom(
                409,
                'error',
                'Ce site est utilisé (points de vente, budgets ou stock) et ne peut être supprimé.'
            );
        }

        $site->delete();

        return ApiResponse::deleted('Site supprimé avec succès.');
    }
}
