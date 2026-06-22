<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CatalogueFournisseur;
use App\Models\CommandeClient;
use App\Models\CommandeFournisseur;
use App\Models\FicheTechnique;
use App\Models\ParametreEtablissement;
use App\Models\Site;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Génère les documents PDF (lecture seule sur les modules métier). Chaque
 * document est rendu en deux temps : `html…()` produit le HTML (testable
 * isolément) ; la méthode publique homonyme renvoie un flux PDF inline, jamais
 * stocké.
 *
 * L'en-tête/pied de chaque document est entièrement alimenté par la fiche
 * `parametres_etablissement` du SITE concerné : aucune donnée d'établissement
 * n'est codée en dur.
 */
class PdfService
{
    /**
     * Bon de commande fournisseur (A4).
     *
     * Le site de l'en-tête n'est pas porté par la commande : on prend le site
     * passé en query (`?site_id=`) s'il est fourni, sinon le site PRINCIPAL.
     */
    public function htmlBonCommandeFournisseur(CommandeFournisseur $commande, ?int $siteId = null): string
    {
        $commande->loadMissing(['fournisseur', 'lignes.matierePremiere', 'lignes.unite']);

        $site = $siteId !== null ? Site::find($siteId) : $this->sitePrincipal();
        $parametre = $this->parametresPourSite($site);

        return View::make('pdf.bon-commande-fournisseur', [
            'commande' => $commande,
            'parametre' => $parametre,
            'logoDataUri' => $this->logoDataUri($parametre),
        ])->render();
    }

    public function bonCommandeFournisseur(CommandeFournisseur $commande, ?int $siteId = null): Response
    {
        return $this->streamA4(
            $this->htmlBonCommandeFournisseur($commande, $siteId),
            "bon-commande-{$commande->numero}.pdf",
        );
    }

    /**
     * Facture client (A4). Site = celui du point de vente de la commande.
     */
    public function htmlFactureClient(CommandeClient $commande): string
    {
        $commande->loadMissing([
            'table.pointDeVente.site',
            'user',
            'lignes.produit',
            'paiements.modePaiement',
            'remises',
        ]);

        $site = $commande->table?->pointDeVente?->site;
        $parametre = $this->parametresPourSite($site);

        return View::make('pdf.facture-client', [
            'commande' => $commande,
            'parametre' => $parametre,
            'logoDataUri' => $this->logoDataUri($parametre),
        ])->render();
    }

    public function factureClient(CommandeClient $commande): Response
    {
        return $this->streamA4(
            $this->htmlFactureClient($commande),
            "facture-{$commande->id}.pdf",
        );
    }

    /**
     * Reçu de paiement (ticket 80mm).
     */
    public function htmlRecuClient(CommandeClient $commande): string
    {
        $commande->loadMissing([
            'table.pointDeVente.site',
            'user',
            'lignes.produit',
            'paiements.modePaiement',
        ]);

        $site = $commande->table?->pointDeVente?->site;
        $parametre = $this->parametresPourSite($site);

        return View::make('pdf.recu-client', [
            'commande' => $commande,
            'parametre' => $parametre,
        ])->render();
    }

    public function recuClient(CommandeClient $commande): Response
    {
        // Largeur ticket 80mm = 226.77pt ; hauteur volontairement large, dompdf
        // tronque au contenu. La taille est aussi déclarée en CSS (@page).
        return Pdf::loadHTML($this->htmlRecuClient($commande))
            ->setPaper([0, 0, 226.77, 800])
            ->stream("recu-{$commande->id}.pdf");
    }

    /**
     * Fiche technique (A4, atelier cuisine).
     */
    public function htmlFicheTechnique(FicheTechnique $fiche): string
    {
        $fiche->loadMissing([
            'produit',
            'lignesMatiere.matierePremiere',
            'lignesMatiere.unite',
            'lignesSousRecette.sousRecette',
        ]);

        $parametre = $this->parametresPourSite($this->sitePrincipal());

        return View::make('pdf.fiche-technique', [
            'fiche' => $fiche,
            'parametre' => $parametre,
            'logoDataUri' => $this->logoDataUri($parametre),
            // Coût unitaire de référence par matière = meilleur prix catalogue,
            // miroir lecture seule de FicheTechniqueService::recalculerCoutMatiere.
            'coutUnitaireParMatiere' => $this->coutUnitaireReference($fiche),
        ])->render();
    }

    public function ficheTechnique(FicheTechnique $fiche): Response
    {
        return $this->streamA4(
            $this->htmlFicheTechnique($fiche),
            "fiche-technique-{$fiche->id}.pdf",
        );
    }

    private function streamA4(string $html, string $nom): Response
    {
        return Pdf::loadHTML($html)->setPaper('a4')->stream($nom);
    }

    /**
     * Fiche de paramètres d'un site (null si site inconnu ou non configuré).
     */
    private function parametresPourSite(?Site $site): ?ParametreEtablissement
    {
        if ($site === null) {
            return null;
        }

        return ParametreEtablissement::where('site_id', $site->id)->first();
    }

    private function sitePrincipal(): ?Site
    {
        return Site::where('code', 'PRINCIPAL')->first();
    }

    /**
     * Logo encodé en data URI (intégrable par dompdf sans accès disque), ou null.
     */
    private function logoDataUri(?ParametreEtablissement $parametre): ?string
    {
        if ($parametre?->logo_path === null) {
            return null;
        }

        $disque = Storage::disk('public');

        if (! $disque->exists($parametre->logo_path)) {
            return null;
        }

        $contenu = base64_encode($disque->get($parametre->logo_path));
        $mime = $disque->mimeType($parametre->logo_path) ?: 'image/png';

        return "data:{$mime};base64,{$contenu}";
    }

    /**
     * Coût unitaire de référence (meilleur prix catalogue) par matière des lignes
     * de la fiche : MIN(prix_unitaire / qte_par_unite_achat).
     *
     * @return Collection<int, float>
     */
    private function coutUnitaireReference(FicheTechnique $fiche)
    {
        return CatalogueFournisseur::query()
            ->whereIn('matiere_premiere_id', $fiche->lignesMatiere->pluck('matiere_premiere_id'))
            ->groupBy('matiere_premiere_id')
            ->selectRaw('matiere_premiere_id, MIN(prix_unitaire / NULLIF(qte_par_unite_achat, 0)) AS cout_unitaire')
            ->pluck('cout_unitaire', 'matiere_premiere_id');
    }
}
