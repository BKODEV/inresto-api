<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CommandeClient;
use App\Models\CommandeFournisseur;
use App\Models\FicheTechnique;
use App\Services\PdfService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Génération des documents PDF. On réutilise les permissions de LECTURE de chaque
 * module (pas de permission transverse dédiée) et on ne modifie aucun controller
 * métier : ce controller est en lecture seule.
 */
class PdfController extends Controller
{
    public function __construct(private readonly PdfService $service) {}

    public function bonCommandeFournisseur(Request $request, CommandeFournisseur $commande_fournisseur): Response
    {
        $this->authorize('view', $commande_fournisseur);

        $siteId = $request->filled('site_id') ? $request->integer('site_id') : null;

        return $this->service->bonCommandeFournisseur($commande_fournisseur, $siteId);
    }

    public function factureClient(CommandeClient $commande): Response
    {
        $this->authorize('view', $commande);

        return $this->service->factureClient($commande);
    }

    public function recuClient(CommandeClient $commande): Response
    {
        $this->authorize('view', $commande);

        return $this->service->recuClient($commande);
    }

    public function ficheTechnique(FicheTechnique $fiche_technique): Response
    {
        $this->authorize('view', $fiche_technique);

        return $this->service->ficheTechnique($fiche_technique);
    }
}
