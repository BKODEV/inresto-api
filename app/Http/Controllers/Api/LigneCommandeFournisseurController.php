<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\CommandeFournisseur\StoreLigneCommandeFournisseurRequest;
use App\Http\Requests\CommandeFournisseur\UpdateLigneCommandeFournisseurRequest;
use App\Http\Resources\LigneCommandeFournisseurResource;
use App\Models\CommandeFournisseur;
use App\Models\LigneCommandeFournisseur;
use App\Services\CommandeFournisseurService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class LigneCommandeFournisseurController extends Controller
{
    public function __construct(private readonly CommandeFournisseurService $service) {}

    /**
     * Ajoute une ligne à une commande — uniquement en brouillon.
     */
    public function store(StoreLigneCommandeFournisseurRequest $request, CommandeFournisseur $commande_fournisseur): JsonResponse
    {
        $ligne = $this->service->ajouterLigne($commande_fournisseur, $request->validated());

        return ApiResponse::created(
            new LigneCommandeFournisseurResource($ligne),
            'Ligne ajoutée à la commande.'
        );
    }

    /**
     * Met à jour une ligne — uniquement en brouillon.
     */
    public function update(
        UpdateLigneCommandeFournisseurRequest $request,
        CommandeFournisseur $commande_fournisseur,
        LigneCommandeFournisseur $ligne,
    ): JsonResponse {
        $this->assertLigneAppartient($commande_fournisseur, $ligne);

        $ligne = $this->service->mettreAJourLigne($ligne, $request->validated());

        return ApiResponse::updated(
            new LigneCommandeFournisseurResource($ligne),
            'Ligne de commande mise à jour.'
        );
    }

    /**
     * Supprime une ligne — uniquement en brouillon.
     */
    public function destroy(CommandeFournisseur $commande_fournisseur, LigneCommandeFournisseur $ligne): JsonResponse
    {
        $this->authorize('update', $commande_fournisseur);
        $this->assertLigneAppartient($commande_fournisseur, $ligne);

        $this->service->supprimerLigne($ligne);

        return ApiResponse::deleted('Ligne de commande supprimée.');
    }

    /**
     * Garantit que la ligne appartient bien à la commande de l'URL.
     */
    private function assertLigneAppartient(CommandeFournisseur $commande, LigneCommandeFournisseur $ligne): void
    {
        if ($ligne->commande_fournisseur_id !== $commande->id) {
            throw new NotFoundHttpException('Ligne introuvable pour cette commande.');
        }
    }
}
