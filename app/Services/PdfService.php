<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\TypeImpression;
use App\Models\BonCuisine;
use App\Models\CatalogueFournisseur;
use App\Models\CommandeClient;
use App\Models\CommandeFournisseur;
use App\Models\FicheTechnique;
use App\Models\ParametreEtablissement;
use App\Models\PointDeVente;
use App\Models\SessionCaisse;
use App\Models\Site;
use App\Models\TableSalle;
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
    public function __construct(private readonly QrCodeService $qr) {}

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
     * Ticket client 80mm, dans l'un de ses deux états.
     *
     * Addition et reçu partagent le gabarit parce qu'ils partagent le contenu :
     * ce qui les sépare est ce qu'ils affirment. L'addition demande le paiement
     * et n'affiche donc aucune ligne de paiement ; le reçu le prouve, et lui
     * seul porte la monnaie rendue.
     */
    public function htmlTicketClient(
        CommandeClient $commande,
        TypeImpression $type = TypeImpression::Recu,
    ): string {
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
            'type' => $type,
        ])->render();
    }

    public function ticketClient(
        CommandeClient $commande,
        TypeImpression $type = TypeImpression::Recu,
    ): Response {
        return $this->streamTicket80(
            $this->htmlTicketClient($commande, $type),
            "{$type->value}-{$commande->id}.pdf"
        );
    }

    /**
     * Rapport de fermeture de caisse — le « ticket Z », qui sort de la même
     * imprimante que les reçus et se classe dans le tiroir.
     *
     * Le layout A4 commun n'est pas réutilisable ici : c'est, avec le ticket
     * client, l'un des deux seuls documents autonomes du projet.
     */
    public function htmlRapportFermeture(SessionCaisse $session): string
    {
        $session->loadMissing(['caisse.pointDeVente.site.parametreEtablissement', 'user', 'fermePar']);

        $site = $session->caisse?->pointDeVente?->site;

        return View::make('pdf.rapport-fermeture', [
            'rapport' => app(RapportFermetureService::class)->pour($session),
            'parametre' => $this->parametresPourSite($site),
        ])->render();
    }

    public function rapportFermeture(SessionCaisse $session): Response
    {
        return $this->streamTicket80(
            $this->htmlRapportFermeture($session),
            "rapport-fermeture-{$session->id}.pdf"
        );
    }

    /**
     * Bons de cuisine 80mm, un par page.
     *
     * Aucun en-tête d'établissement : le bon ne quitte pas la cuisine.
     *
     * @param  Collection<int, BonCuisine>  $bons
     * @param  array<int, bool>  $duplicatas  identifiants des bons déjà imprimés auparavant
     */
    public function htmlBonsCuisine(Collection $bons, array $duplicatas = []): string
    {
        return View::make('pdf.bon-cuisine', [
            'bons' => $bons,
            'duplicatas' => $duplicatas,
        ])->render();
    }

    /**
     * Le papier est à la hauteur du bon le plus long, et non aux 800pt du reçu :
     * dompdf impose la même taille à toutes les pages, et une impression en lot
     * de dix bons déroulerait sinon près de trois mètres de rouleau.
     *
     * @param  Collection<int, BonCuisine>  $bons
     * @param  array<int, bool>  $duplicatas
     */
    public function bonsCuisine(Collection $bons, array $duplicatas, string $nomFichier): Response
    {
        $hauteur = $bons->max(fn (BonCuisine $bon): float => self::hauteurBon($bon)) ?? 200.0;

        return Pdf::loadHTML($this->htmlBonsCuisine($bons, $duplicatas))
            ->setPaper([0, 0, 226.77, $hauteur])
            ->stream($nomFichier);
    }

    /**
     * Estimation généreuse, en points, de la hauteur d'un bon : en-tête fixe,
     * puis une rangée par plat et une par remarque. Un libellé long passe sur
     * deux lignes, d'où la marge par plat.
     */
    private static function hauteurBon(BonCuisine $bon): float
    {
        $entete = 130.0;
        $parPlat = 30.0;
        $parRemarque = 22.0;

        return $entete
            + $bon->lignes->count() * $parPlat
            + $bon->lignes->filter(fn ($ligne): bool => filled($ligne->commentaire))->count() * $parRemarque;
    }

    /**
     * Ticket 80mm = 226.77pt de large ; hauteur volontairement généreuse,
     * dompdf tronque au contenu. La taille est aussi déclarée en CSS (@page).
     */
    private function streamTicket80(string $html, string $nomFichier): Response
    {
        return Pdf::loadHTML($html)
            ->setPaper([0, 0, 226.77, 800])
            ->stream($nomFichier);
    }

    /**
     * Fiche technique (A4, atelier cuisine).
     */
    public function htmlFicheTechnique(FicheTechnique $fiche, ?int $siteId = null): string
    {
        $fiche->loadMissing([
            'produit',
            'lignesMatiere.matierePremiere',
            'lignesMatiere.unite',
            'lignesSousRecette.sousRecette',
            'etapes',
            'materiels',
            'pointsCritiques',
        ]);

        // Une fiche technique est une définition partagée : elle ne porte aucun
        // site. L'en-tête prend donc le site depuis lequel on imprime (`?site_id=`),
        // et retombe sur le site PRINCIPAL à défaut — comme le bon de commande.
        $site = $siteId !== null ? Site::find($siteId) : $this->sitePrincipal();
        $parametre = $this->parametresPourSite($site);

        return View::make('pdf.fiche-technique', [
            'fiche' => $fiche,
            'parametre' => $parametre,
            'logoDataUri' => $this->logoDataUri($parametre),
            // Coût unitaire de référence par matière = meilleur prix catalogue,
            // miroir lecture seule de FicheTechniqueService::recalculerCoutMatiere.
            'coutUnitaireParMatiere' => $this->coutUnitaireReference($fiche),
        ])->render();
    }

    public function ficheTechnique(FicheTechnique $fiche, ?int $siteId = null): Response
    {
        return $this->streamA4(
            $this->htmlFicheTechnique($fiche, $siteId),
            "fiche-technique-{$fiche->id}.pdf",
        );
    }

    /**
     * Chevalet QR d'une table (A6) : le convive scanne, la page menu s'ouvre.
     *
     * Le QR encode l'URL publique complète de la table ; l'en-tête vient des
     * paramètres du site du point de vente.
     */
    public function htmlQrTable(TableSalle $table): string
    {
        $table->loadMissing('pointDeVente.site');

        $parametre = $this->parametresPourSite($table->pointDeVente?->site);

        return View::make('pdf.qr-table', [
            'table' => $table,
            'parametre' => $parametre,
            'logoDataUri' => $this->logoDataUri($parametre),
            'qrDataUri' => $this->qr->dataUri((string) $table->menuUrl(), 600),
        ])->render();
    }

    public function qrTable(TableSalle $table): Response
    {
        return Pdf::loadHTML($this->htmlQrTable($table))
            ->setPaper('a6')
            ->stream("qr-table-{$table->numero}.pdf");
    }

    /**
     * Planche A4 des QR de toutes les tables d'un point de vente : une seule
     * impression pour équiper la salle entière.
     */
    public function htmlQrPlanchePointDeVente(PointDeVente $pointDeVente): string
    {
        $pointDeVente->loadMissing('site');

        $parametre = $this->parametresPourSite($pointDeVente->site);

        // Une table sans jeton n'a pas de QR imprimable : on l'omet plutôt que
        // de produire une case vide (`inresto:backfill-table-tokens` corrige).
        // Une table archivée n'est plus en salle : imprimer son QR remettrait en
        // circulation un autocollant qui ne sert plus aucun menu.
        $tables = TableSalle::query()
            ->where('point_de_vente_id', $pointDeVente->id)
            ->whereNotNull('public_token')
            ->whereNull('archivee_le')
            ->orderBy('numero')
            ->get();

        return View::make('pdf.qr-planche', [
            'pointDeVente' => $pointDeVente,
            'parametre' => $parametre,
            'tables' => $tables,
            'qrParTable' => $tables->mapWithKeys(
                fn (TableSalle $table) => [$table->id => $this->qr->dataUri((string) $table->menuUrl(), 400)]
            ),
        ])->render();
    }

    public function qrPlanchePointDeVente(PointDeVente $pointDeVente): Response
    {
        return $this->streamA4(
            $this->htmlQrPlanchePointDeVente($pointDeVente),
            "qr-planche-{$pointDeVente->id}.pdf",
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
