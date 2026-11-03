<?php

declare(strict_types=1);

namespace App\Services\Reporting;

use App\Models\Budget;
use App\Support\Periode;
use App\Support\PeriodeResolver;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

/**
 * Agrégateur du tableau de bord de tête (lecture seule). Ne réimplémente AUCUN
 * calcul : compose les services reporting existants pour produire, en une seule
 * réponse, les indicateurs du jour et du mois courant + les listes de tête.
 *
 * Périodes implicites :
 *  - jour  : aujourd'hui (00:00 → 23:59) ;
 *  - mois  : mois courant (1er → dernier jour) ;
 *  - série : 30 derniers jours glissants (plage J-29 → J).
 */
class DashboardReportService
{
    public function __construct(
        private readonly VentesReportService $ventes,
        private readonly FoodCostReportService $foodCost,
        private readonly StockReportService $stock,
        private readonly BudgetReportService $budget,
        private readonly PeriodeResolver $periodeResolver,
    ) {}

    /**
     * Réponse agrégée complète du tableau de bord pour un périmètre de sites.
     *
     * @param  int[]|null  $siteIds  Périmètre (null = tous les sites).
     * @return array<string, mixed>
     */
    public function apercu(?array $siteIds): array
    {
        $jour = $this->periodeJour();
        $mois = $this->periodeMois();

        return [
            'ca' => [
                'jour' => $this->caEtCommandes($jour, $siteIds),
                'mois' => $this->caEtCommandes($mois, $siteIds),
            ],
            'food_cost' => $this->foodCostMois($mois, $siteIds),
            'budget' => $this->budgetMois($mois, $siteIds),
            'top_produits' => $this->topProduits($mois, $siteIds),
            'evolution_ca' => $this->evolutionCa($siteIds),
            'alertes_stock' => $this->alertesStock($siteIds),
            'modes_paiement' => $this->modesPaiement($mois, $siteIds),
        ];
    }

    /**
     * CA total et nombre de commandes d'une période, recomposés à partir du
     * détail par point de vente (base « vendu »).
     *
     * @param  int[]|null  $siteIds
     * @return array{montant: float, nb_commandes: int}
     */
    private function caEtCommandes(Periode $periode, ?array $siteIds): array
    {
        $lignes = $this->ventes->caParPointDeVente($periode, $siteIds, null);

        return [
            'montant' => round((float) array_sum(array_column($lignes, 'ca')), 2),
            'nb_commandes' => (int) array_sum(array_column($lignes, 'nombre_commandes')),
        ];
    }

    /**
     * Synthèse food cost du mois, projetée sur les clés du tableau de bord.
     *
     * @param  int[]|null  $siteIds
     * @return array{reel_pct: float, theorique_pct: float, ecart_points: float, marge_brute: float}
     */
    private function foodCostMois(Periode $mois, ?array $siteIds): array
    {
        $synthese = $this->foodCost->synthese($mois, $siteIds, null);

        return [
            'reel_pct' => $synthese['food_cost_reel_pct'],
            'theorique_pct' => $synthese['food_cost_theorique_pct'],
            'ecart_points' => $synthese['ecart_global_points'],
            'marge_brute' => $synthese['marge_brute_totale'],
        ];
    }

    /**
     * Budget du mois agrégé sur le périmètre : enveloppe et consommation sommées,
     * pourcentage recalculé, niveau d'alerte = le plus sévère des budgets en
     * périmètre (réutilise {@see Budget::niveauAlerte()}).
     *
     * @param  int[]|null  $siteIds
     * @return array{montant_alloue: float, montant_consomme: float, pourcentage_consomme: float, niveau_alerte: string}
     */
    private function budgetMois(Periode $mois, ?array $siteIds): array
    {
        /** @var Collection<int, Budget> $budgets */
        $budgets = $this->budget->consommation($mois, $siteIds);

        $alloue = round((float) $budgets->sum(fn (Budget $b): float => (float) $b->montant_alloue), 2);
        $consomme = round((float) $budgets->sum(fn (Budget $b): float => $b->montantConsomme()), 2);

        return [
            'montant_alloue' => $alloue,
            'montant_consomme' => $consomme,
            'pourcentage_consomme' => $alloue > 0 ? round($consomme / $alloue * 100, 2) : 0.0,
            'niveau_alerte' => $this->niveauAlerteLePlusSevere($budgets),
        ];
    }

    /**
     * Niveau d'alerte le plus sévère parmi les budgets du périmètre.
     *
     * @param  Collection<int, Budget>  $budgets
     */
    private function niveauAlerteLePlusSevere(Collection $budgets): string
    {
        $severite = ['aucun' => 0, 'seuil_75' => 1, 'seuil_90' => 2, 'depassement' => 3];
        $pire = 'aucun';

        foreach ($budgets as $b) {
            if ($severite[$b->niveauAlerte()] > $severite[$pire]) {
                $pire = $b->niveauAlerte();
            }
        }

        return $pire;
    }

    /**
     * Top 5 produits du mois par CA, projeté sur les clés du tableau de bord.
     *
     * @param  int[]|null  $siteIds
     * @return array<int, array{produit: string, quantite_vendue: float, ca: float}>
     */
    private function topProduits(Periode $mois, ?array $siteIds): array
    {
        return array_map(fn (array $row): array => [
            'produit' => $row['produit'],
            'quantite_vendue' => $row['quantite_vendue'],
            'ca' => $row['ca'],
        ], $this->ventes->produits($mois, $siteIds, null, 'ca', 'desc', 5));
    }

    /**
     * Évolution du CA sur les 30 derniers jours (un point par jour, CA seul).
     *
     * @param  int[]|null  $siteIds
     * @return array<int, array{label: string, ca: float}>
     */
    private function evolutionCa(?array $siteIds): array
    {
        $serie = $this->ventes->evolution($this->periodeTrenteJours(), $siteIds, null);

        return array_map(fn (array $point): array => [
            'label' => $point['label'],
            'ca' => $point['ca'],
        ], $serie);
    }

    /**
     * Alertes de stock instantanées (matières sous seuil), limitées à 10 items.
     *
     * @param  int[]|null  $siteIds
     * @return array{nombre: int, items: array<int, array<string, mixed>>}
     */
    private function alertesStock(?array $siteIds): array
    {
        $alertes = $this->stock->alertes($siteIds);

        $items = array_map(fn (array $a): array => [
            'matiere_premiere_id' => $a['matiere_premiere_id'],
            'matiere' => $a['matiere'],
            'stock_courant' => $a['stock_courant'],
            'seuil' => $a['seuil'],
            'unite' => $a['unite'],
            'site_id' => $a['site_id'],
            'site' => $a['site'],
        ], array_slice($alertes, 0, 10));

        return [
            'nombre' => count($alertes),
            'items' => $items,
        ];
    }

    /**
     * CA encaissé du mois par mode de paiement, projeté sur les clés du tableau
     * de bord.
     *
     * @param  int[]|null  $siteIds
     * @return array<int, array{mode: string, montant: float, part_pct: float}>
     */
    private function modesPaiement(Periode $mois, ?array $siteIds): array
    {
        return array_map(fn (array $row): array => [
            'mode' => $row['mode'],
            'montant' => $row['montant_total'],
            'part_pct' => $row['part_pct'],
        ], $this->ventes->modesPaiement($mois, $siteIds, null));
    }

    /**
     * Les trois périodes passent par le resolver plutôt que par un `new Periode`
     * à la main : une seule définition de « aujourd'hui », « ce mois-ci » et
     * « les 30 derniers jours » pour toute l'application.
     */
    private function periodeJour(): Periode
    {
        return $this->periodeResolver->resolve(['periode' => 'jour']);
    }

    private function periodeMois(): Periode
    {
        return $this->periodeResolver->resolve(['periode' => 'mois']);
    }

    private function periodeTrenteJours(): Periode
    {
        return $this->periodeResolver->resolve([
            'periode' => 'plage',
            'date_debut' => Carbon::today()->subDays(29)->toDateString(),
            'date_fin' => Carbon::today()->toDateString(),
        ]);
    }
}
