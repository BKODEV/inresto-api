<?php

declare(strict_types=1);

namespace App\Services\Reporting\Concerns;

use App\Support\Periode;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Briques de requêtes partagées par les rapports adossés au « vendu »
 * (food cost, ventes). Une vente compte dès le statut `servie` ; l'encaissement
 * ne change pas la base.
 *
 * DATE DE RÉFÉRENCE = `sessions_caisse.ouvert_le`, et non l'horodatage de la
 * note. Une vente appartient au SERVICE qui l'a produite, et le service, c'est
 * la session de caisse.
 *
 * > **Pourquoi ?** Un service qui commence le 3 à 18h et ferme le 4 à 2h coupait
 * > sa soirée en deux sur le calendrier : la fin de service comptait pour le
 * > lendemain, et le chiffre du 3 était amputé de ses dernières tables. Dater par
 * > la session recolle la soirée, sans aucun réglage : `session_caisse_id` est
 * > NOT NULL sur `commandes_client`, donc toute note a un service d'origine.
 *
 * L'alternative — une « heure de bascule » paramétrée par site — a été écartée :
 * elle demandait un réglage que personne ne pense à faire, changeait tous les
 * chiffres quand on y touchait, contaminait les colonnes de type `date` dès que
 * la borne haute glissait, et ne pouvait pas s'appliquer sur un périmètre
 * multi-site. C'était une approximation de la session ; autant prendre la session.
 *
 * Corollaire assumé : ce rattachement ne vaut que pour ce qui PASSE par une
 * caisse. Le stock, les réceptions, les dépenses et les inventaires restent
 * datés au calendrier — ce sont des travaux de journée, pas de service.
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
     * périmètre. Expose les alias `cc`, `l`, `pdv`, `sc`.
     *
     * @param  int[]|null  $siteIds
     */
    protected function lignesVenduesQuery(Periode $periode, ?array $siteIds, ?int $pdvId): Builder
    {
        return DB::table('lignes_commande_client as l')
            ->join('commandes_client as cc', 'cc.id', '=', 'l.commande_client_id')
            ->join('sessions_caisse as sc', 'sc.id', '=', 'cc.session_caisse_id')
            ->join('tables_salle as t', 't.id', '=', 'cc.table_id')
            ->join('points_de_vente as pdv', 'pdv.id', '=', 't.point_de_vente_id')
            ->whereIn('cc.statut', $this->statutsVendus)
            ->where('l.statut', 'actif')
            ->whereBetween('sc.ouvert_le', [$periode->debut, $periode->fin])
            ->when($siteIds !== null, fn (Builder $q) => $q->whereIn('pdv.site_id', $siteIds))
            ->when($pdvId !== null, fn (Builder $q) => $q->where('pdv.id', $pdvId));
    }

    /**
     * Commandes vendues sur la période et le périmètre. Expose les alias `cc`,
     * `pdv`, `sc`.
     *
     * @param  int[]|null  $siteIds
     */
    protected function commandesVenduesQuery(Periode $periode, ?array $siteIds, ?int $pdvId): Builder
    {
        return DB::table('commandes_client as cc')
            ->join('sessions_caisse as sc', 'sc.id', '=', 'cc.session_caisse_id')
            ->join('tables_salle as t', 't.id', '=', 'cc.table_id')
            ->join('points_de_vente as pdv', 'pdv.id', '=', 't.point_de_vente_id')
            ->whereIn('cc.statut', $this->statutsVendus)
            ->whereBetween('sc.ouvert_le', [$periode->debut, $periode->fin])
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
