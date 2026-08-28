<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\TypeImpression;
use App\Http\Controllers\Controller;
use App\Models\CommandeClient;
use App\Models\CommandeFournisseur;
use App\Models\FicheTechnique;
use App\Models\Impression;
use App\Models\PointDeVente;
use App\Models\SessionCaisse;
use App\Models\TableSalle;
use App\Services\PdfService;
use App\Support\SitePerimetre;
use Illuminate\Database\Eloquent\Model;
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

        // Le site choisit l'en-tête (raison sociale, logo) du document : le
        // borner au périmètre évite d'éditer un bon sous l'identité d'un autre.
        SitePerimetre::resoudre($request->user(), $siteId);

        return $this->service->bonCommandeFournisseur($commande_fournisseur, $siteId);
    }

    public function factureClient(CommandeClient $commande): Response
    {
        $this->authorize('view', $commande);

        $this->journaliser($commande, TypeImpression::Facture);

        return $this->service->factureClient($commande);
    }

    /**
     * Addition : le détail et le TOTAL À PAYER, présentés avant l'argent.
     * Journalisée à part du reçu — la réimprimer n'a pas le même poids.
     */
    public function additionClient(CommandeClient $commande): Response
    {
        $this->authorize('view', $commande);

        $this->journaliser($commande, TypeImpression::Addition);

        return $this->service->ticketClient($commande, TypeImpression::Addition);
    }

    /**
     * Reçu : la preuve du paiement, monnaie rendue comprise.
     */
    public function recuClient(CommandeClient $commande): Response
    {
        $this->authorize('view', $commande);

        $this->journaliser($commande, TypeImpression::Recu);

        return $this->service->ticketClient($commande, TypeImpression::Recu);
    }

    /**
     * Trace l'impression d'un document d'argent.
     *
     * La réimpression d'une note est un vecteur de fraude connu — le document
     * ressort, l'encaissement non. Annulations et remises portent déjà leur
     * auteur et leur motif ; l'impression était le seul geste sensible sans
     * aucune trace.
     *
     * Journalisé AVANT le rendu : c'est la demande qu'on enregistre, et un
     * échec de génération du PDF ne doit pas effacer le fait qu'elle a eu lieu.
     */
    private function journaliser(Model $imprimable, TypeImpression $type): void
    {
        Impression::create([
            'user_id' => request()->user()->id,
            'type' => $type->value,
            'imprimable_type' => $imprimable->getMorphClass(),
            'imprimable_id' => $imprimable->getKey(),
        ]);
    }

    /**
     * Rapport de fermeture d'une session de caisse (ticket 80mm).
     *
     * Journalisé comme les documents client : il fige un rapprochement, et le
     * ressortir après coup mérite une trace au même titre qu'une note
     * réimprimée.
     */
    public function rapportFermeture(SessionCaisse $session): Response
    {
        $this->authorize('view', $session);

        $this->journaliser($session, TypeImpression::RapportFermeture);

        return $this->service->rapportFermeture($session);
    }

    public function ficheTechnique(Request $request, FicheTechnique $fiche_technique): Response
    {
        $this->authorize('view', $fiche_technique);

        $siteId = $request->filled('site_id') ? $request->integer('site_id') : null;

        // Le site choisit l'en-tête (raison sociale, logo) du document.
        SitePerimetre::resoudre($request->user(), $siteId);

        return $this->service->ficheTechnique($fiche_technique, $siteId);
    }

    /**
     * Chevalet QR d'une table (A6).
     */
    public function qrTable(TableSalle $table): Response
    {
        $this->authorize('view', $table);

        return $this->service->qrTable($table);
    }

    /**
     * Planche A4 des QR de toutes les tables d'un point de vente.
     */
    public function qrPlanchePointDeVente(PointDeVente $point_de_vente): Response
    {
        // La planche n'est qu'un lot de QR de tables : c'est la lecture des
        // tables qui la gouverne (`table.view`), pas celle du point de vente.
        $this->authorize('viewAny', TableSalle::class);

        return $this->service->qrPlanchePointDeVente($point_de_vente);
    }
}
