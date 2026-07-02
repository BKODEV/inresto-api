<?php

declare(strict_types=1);

namespace App\Services\Reporting;

use App\Services\Reporting\Concerns\RequetesVente;
use App\Support\Periode;
use Illuminate\Database\Query\Builder;

/**
 * Rapports food cost / marge (lecture seule).
 *
 * Food cost réel vs théorique calculé sur le CA VALORISÉ (lignes vendues avec
 * fiche technique figée). Les lignes sans fiche figée sont isolées dans un bloc
 * `lignes_non_valorisees` et exclues des ratios valorisés.
 */
class FoodCostReportService
{
    use RequetesVente;

    /**
     * Food cost par produit vendu (lignes valorisées), trié par fuite décroissante.
     *
     * @param  int[]|null  $siteIds
     * @return array{produits: array<int, array<string, mixed>>, lignes_non_valorisees: array<string, mixed>}
     */
    public function parProduit(Periode $periode, ?array $siteIds, ?int $pdvId): array
    {
        $rows = $this->lignesVenduesQuery($periode, $siteIds, $pdvId)
            ->join('fiches_techniques as ft', 'ft.id', '=', 'l.fiche_technique_id')
            ->join('produits as pr', 'pr.id', '=', 'l.produit_id')
            ->leftJoinSub($this->coutReelParLigne(), 'mv', 'mv.reference_id', '=', 'l.id')
            ->whereNotNull('l.fiche_technique_id')
            ->groupBy('l.produit_id', 'pr.libelle')
            ->selectRaw(
                'l.produit_id, pr.libelle as produit, '.
                'SUM(l.quantite) as quantite_vendue, '.
                'SUM(l.quantite * l.prix_unitaire) as ca, '.
                'SUM(ft.cout_matiere * l.quantite) as cout_theorique, '.
                'SUM(COALESCE(mv.cout_reel, 0)) as cout_reel'
            )
            ->get();

        $produits = $rows
            ->map(fn ($row) => $this->ligneFoodCost([
                'produit_id' => (int) $row->produit_id,
                'produit' => $row->produit,
            ], (float) $row->quantite_vendue, (float) $row->ca, (float) $row->cout_theorique, (float) $row->cout_reel))
            ->sortByDesc('ecart_food_cost_valeur')
            ->values()
            ->all();

        return [
            'produits' => $produits,
            'lignes_non_valorisees' => $this->lignesNonValorisees($periode, $siteIds, $pdvId),
        ];
    }

    /**
     * Food cost agrégé par point de vente.
     *
     * @param  int[]|null  $siteIds
     * @return array{points_de_vente: array<int, array<string, mixed>>, lignes_non_valorisees: array<string, mixed>}
     */
    public function parPointDeVente(Periode $periode, ?array $siteIds, ?int $pdvId): array
    {
        $rows = $this->lignesVenduesQuery($periode, $siteIds, $pdvId)
            ->join('fiches_techniques as ft', 'ft.id', '=', 'l.fiche_technique_id')
            ->leftJoinSub($this->coutReelParLigne(), 'mv', 'mv.reference_id', '=', 'l.id')
            ->whereNotNull('l.fiche_technique_id')
            ->groupBy('pdv.id', 'pdv.nom')
            ->selectRaw(
                'pdv.id as point_de_vente_id, pdv.nom as point_de_vente, '.
                'SUM(l.quantite) as quantite_vendue, '.
                'SUM(l.quantite * l.prix_unitaire) as ca, '.
                'SUM(ft.cout_matiere * l.quantite) as cout_theorique, '.
                'SUM(COALESCE(mv.cout_reel, 0)) as cout_reel'
            )
            ->get();

        $pdv = $rows
            ->map(fn ($row) => $this->ligneFoodCost([
                'point_de_vente_id' => (int) $row->point_de_vente_id,
                'point_de_vente' => $row->point_de_vente,
            ], (float) $row->quantite_vendue, (float) $row->ca, (float) $row->cout_theorique, (float) $row->cout_reel))
            ->sortByDesc('ecart_food_cost_valeur')
            ->values()
            ->all();

        return [
            'points_de_vente' => $pdv,
            'lignes_non_valorisees' => $this->lignesNonValorisees($periode, $siteIds, $pdvId),
        ];
    }

    /**
     * Synthèse globale (carte de tête).
     *
     * @param  int[]|null  $siteIds
     * @return array<string, mixed>
     */
    public function synthese(Periode $periode, ?array $siteIds, ?int $pdvId): array
    {
        $caTotal = (float) $this->lignesVenduesQuery($periode, $siteIds, $pdvId)
            ->selectRaw('COALESCE(SUM(l.quantite * l.prix_unitaire), 0) as ca')
            ->value('ca');

        $valorise = $this->lignesVenduesQuery($periode, $siteIds, $pdvId)
            ->join('fiches_techniques as ft', 'ft.id', '=', 'l.fiche_technique_id')
            ->leftJoinSub($this->coutReelParLigne(), 'mv', 'mv.reference_id', '=', 'l.id')
            ->whereNotNull('l.fiche_technique_id')
            ->selectRaw(
                'COALESCE(SUM(l.quantite * l.prix_unitaire), 0) as ca_valorise, '.
                'COALESCE(SUM(ft.cout_matiere * l.quantite), 0) as cout_theorique, '.
                'COALESCE(SUM(COALESCE(mv.cout_reel, 0)), 0) as cout_reel'
            )
            ->first();

        $caValorise = (float) $valorise->ca_valorise;
        $coutTheorique = (float) $valorise->cout_theorique;
        $coutReel = (float) $valorise->cout_reel;

        $fcTheoPct = $this->pourcentage($coutTheorique, $caValorise);
        $fcReelPct = $this->pourcentage($coutReel, $caValorise);

        return [
            'ca_total' => round($caTotal, 2),
            'ca_valorise' => round($caValorise, 2),
            'cout_theorique_total' => round($coutTheorique, 2),
            'cout_reel_total' => round($coutReel, 2),
            'food_cost_theorique_pct' => $fcTheoPct,
            'food_cost_reel_pct' => $fcReelPct,
            'ecart_global_points' => round($fcReelPct - $fcTheoPct, 2),
            // Les lignes non valorisées portent un coût réel nul : marge brute = CA total − coût réel valorisé.
            'marge_brute_totale' => round($caTotal - $coutReel, 2),
            'part_ca_non_valorise_pct' => $this->pourcentage($caTotal - $caValorise, $caTotal),
        ];
    }

    /**
     * Bloc des lignes vendues sans fiche figée (échappent à la valorisation).
     *
     * @param  int[]|null  $siteIds
     * @return array{nombre_lignes: int, ca_concerne: float, produits: array<int, array<string, mixed>>}
     */
    public function lignesNonValorisees(Periode $periode, ?array $siteIds, ?int $pdvId): array
    {
        $agregat = $this->baseNonValorisee($periode, $siteIds, $pdvId)
            ->selectRaw('COUNT(*) as nombre_lignes, COALESCE(SUM(l.quantite * l.prix_unitaire), 0) as ca')
            ->first();

        $produits = $this->baseNonValorisee($periode, $siteIds, $pdvId)
            ->join('produits as pr', 'pr.id', '=', 'l.produit_id')
            ->groupBy('l.produit_id', 'pr.libelle')
            ->selectRaw('l.produit_id, pr.libelle as produit, SUM(l.quantite) as quantite_vendue, SUM(l.quantite * l.prix_unitaire) as ca')
            ->get()
            ->map(fn ($row) => [
                'produit_id' => (int) $row->produit_id,
                'produit' => $row->produit,
                'quantite_vendue' => (float) $row->quantite_vendue,
                'ca' => round((float) $row->ca, 2),
            ])
            ->all();

        return [
            'nombre_lignes' => (int) $agregat->nombre_lignes,
            'ca_concerne' => round((float) $agregat->ca, 2),
            'produits' => $produits,
        ];
    }

    /**
     * @param  int[]|null  $siteIds
     */
    private function baseNonValorisee(Periode $periode, ?array $siteIds, ?int $pdvId): Builder
    {
        return $this->lignesVenduesQuery($periode, $siteIds, $pdvId)
            ->whereNull('l.fiche_technique_id');
    }

    /**
     * Assemble une ligne food cost avec ses ratios dérivés.
     *
     * @param  array<string, mixed>  $identite
     * @return array<string, mixed>
     */
    private function ligneFoodCost(array $identite, float $quantite, float $ca, float $coutTheorique, float $coutReel): array
    {
        $fcTheoPct = $this->pourcentage($coutTheorique, $ca);
        $fcReelPct = $this->pourcentage($coutReel, $ca);

        return array_merge($identite, [
            'quantite_vendue' => $quantite,
            'ca' => round($ca, 2),
            'cout_theorique' => round($coutTheorique, 2),
            'cout_reel' => round($coutReel, 2),
            'food_cost_theorique_pct' => $fcTheoPct,
            'food_cost_reel_pct' => $fcReelPct,
            'ecart_food_cost_valeur' => round($coutReel - $coutTheorique, 2),
            'ecart_food_cost_points' => round($fcReelPct - $fcTheoPct, 2),
            'marge_brute_reelle' => round($ca - $coutReel, 2),
        ]);
    }

    private function pourcentage(float $numerateur, float $denominateur): float
    {
        return $denominateur > 0 ? round($numerateur / $denominateur * 100, 2) : 0.0;
    }
}
