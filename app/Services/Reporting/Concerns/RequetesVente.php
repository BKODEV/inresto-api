<?php

declare(strict_types=1);

namespace App\Services\Reporting\Concerns;

use App\Support\Periode;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Briques de requêtes partagées par les rapports adossés au « vendu »
 * (food cost, ventes). Une vente compte dès le statut `servie` ; l'encaissement
 * ne change pas la base. Date de référence = `commandes_client.date_heure`.
 *
 * La chaîne site/PDV passe par : commandes_client → tables_salle → points_de_vente.
 */
trait RequetesVente
{
    /**
     * @var string[] Statuts d'une commande considérée « vendue ».
     */
    protected array $statutsVendus = ['servie', 'encaissee'];

    /**
     * Lignes de commande actives des commandes vendues sur la période et le
     * périmètre. Expose les alias `cc`, `l`, `pdv`.
     *
     * @param  int[]|null  $siteIds
     */
    protected function lignesVenduesQuery(Periode $periode, ?array $siteIds, ?int $pdvId): Builder
    {
        return DB::table('lignes_commande_client as l')
            ->join('commandes_client as cc', 'cc.id', '=', 'l.commande_client_id')
            ->join('tables_salle as t', 't.id', '=', 'cc.table_id')
            ->join('points_de_vente as pdv', 'pdv.id', '=', 't.point_de_vente_id')
            ->whereIn('cc.statut', $this->statutsVendus)
            ->where('l.statut', 'actif')
            ->whereBetween('cc.date_heure', [$periode->debut, $periode->fin])
            ->when($siteIds !== null, fn (Builder $q) => $q->whereIn('pdv.site_id', $siteIds))
            ->when($pdvId !== null, fn (Builder $q) => $q->where('pdv.id', $pdvId));
    }

    /**
     * Commandes vendues sur la période et le périmètre. Expose les alias `cc`, `pdv`.
     *
     * @param  int[]|null  $siteIds
     */
    protected function commandesVenduesQuery(Periode $periode, ?array $siteIds, ?int $pdvId): Builder
    {
        return DB::table('commandes_client as cc')
            ->join('tables_salle as t', 't.id', '=', 'cc.table_id')
            ->join('points_de_vente as pdv', 'pdv.id', '=', 't.point_de_vente_id')
            ->whereIn('cc.statut', $this->statutsVendus)
            ->whereBetween('cc.date_heure', [$periode->debut, $periode->fin])
            ->when($siteIds !== null, fn (Builder $q) => $q->whereIn('pdv.site_id', $siteIds))
            ->when($pdvId !== null, fn (Builder $q) => $q->where('pdv.id', $pdvId));
    }

    /**
     * Sous-requête : coût réel par ligne de vente = Σ (quantite × pmp_au_moment)
     * des mouvements de sortie liés (déjà figés). Groupée par `reference_id`.
     */
    protected function coutReelParLigne(): Builder
    {
        return DB::table('mouvements_stock')
            ->select('reference_id', DB::raw('SUM(quantite * pmp_au_moment) as cout_reel'))
            ->where('type', 'sortie_vente')
            ->where('reference_type', 'lignes_commande_client')
            ->groupBy('reference_id');
    }
}
