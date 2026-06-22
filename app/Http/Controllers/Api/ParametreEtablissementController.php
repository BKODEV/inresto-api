<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\ParametreEtablissement\StoreParametreEtablissementRequest;
use App\Http\Requests\ParametreEtablissement\UploadLogoRequest;
use App\Http\Resources\ParametreEtablissementResource;
use App\Models\ParametreEtablissement;
use App\Models\Site;
use App\Services\ParametreEtablissementService;
use Illuminate\Http\JsonResponse;

class ParametreEtablissementController extends Controller
{
    public function __construct(private readonly ParametreEtablissementService $service) {}

    /**
     * Fiche de paramètres du site (404 tant qu'elle n'a pas été créée).
     */
    public function show(Site $site): JsonResponse
    {
        $parametre = ParametreEtablissement::where('site_id', $site->id)->first();

        $this->authorize('view', $parametre ?? new ParametreEtablissement(['site_id' => $site->id]));

        if ($parametre === null) {
            return ApiResponse::notFound('Aucun paramètre d\'établissement pour ce site.');
        }

        return ApiResponse::success(
            new ParametreEtablissementResource($parametre),
            'Paramètres d\'établissement récupérés.'
        );
    }

    /**
     * Upsert de la fiche du site (un site n'a qu'une fiche).
     */
    public function store(StoreParametreEtablissementRequest $request, Site $site): JsonResponse
    {
        $parametre = $this->service->creerOuMettreAJour($site, $request->validated());

        return ApiResponse::updated(
            new ParametreEtablissementResource($parametre),
            'Paramètres d\'établissement enregistrés.'
        );
    }

    /**
     * Envoie/écrase le logo du site (la fiche doit déjà exister → 404 sinon).
     */
    public function uploadLogo(UploadLogoRequest $request, Site $site): JsonResponse
    {
        $parametre = ParametreEtablissement::where('site_id', $site->id)->first();

        if ($parametre === null) {
            return ApiResponse::notFound('Créez d\'abord la fiche de paramètres de ce site.');
        }

        $parametre = $this->service->enregistrerLogo($parametre, $request->file('logo'));

        return ApiResponse::updated(
            new ParametreEtablissementResource($parametre),
            'Logo mis à jour.'
        );
    }

    /**
     * Supprime le logo du site (fichier effacé, logo_path remis à null).
     */
    public function deleteLogo(Site $site): JsonResponse
    {
        $parametre = ParametreEtablissement::where('site_id', $site->id)->first();

        $this->authorize('update', $parametre ?? new ParametreEtablissement(['site_id' => $site->id]));

        if ($parametre === null) {
            return ApiResponse::notFound('Aucun paramètre d\'établissement pour ce site.');
        }

        $parametre = $this->service->supprimerLogo($parametre);

        return ApiResponse::updated(
            new ParametreEtablissementResource($parametre),
            'Logo supprimé.'
        );
    }
}
