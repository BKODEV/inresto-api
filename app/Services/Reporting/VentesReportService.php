<?php

declare(strict_types=1);

namespace App\Services\Reporting;

use App\Services\Reporting\Concerns\RequetesVente;
use App\Support\Periode;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Rapports de ventes (lecture seule). Base « vendu » (servie + encaissee) pour
 * le CA, SAUF `modesPaiement` qui est une vue trésorerie basée sur l'encaissé.
 */
class VentesReportService
{
    use RequetesVente;

    /**
     * CA par point de vente : CA (Σ lignes), nombre de commandes, panier moyen.
     *
     * @param  int[]|null  $siteIds
     * @return array<int, array<string, mixed>>
     */
    public function caParPointDeVente(Periode $periode, ?array $siteIds, ?int $pdvId): array
    {
        return $this->commandesVenduesQuery($periode, $siteIds, $pdvId)
            ->leftJoin('lignes_commande_client as l', function ($join) {
                $join->on('l.commande_client_id', '=', 'cc.id')->where('l.statut', '=', 'actif');
            })
            ->groupBy('pdv.id', 'pdv.nom')
            ->selectRaw(
                'pdv.id as point_de_vente_id, pdv.nom as point_de_vente, '.
                'COUNT(DISTINCT cc.id) as nombre_commandes, '.
                'COALESCE(SUM(l.quantite * l.prix_unitaire), 0) as ca'
            )
            ->get()
            ->map(function ($row) {
                $ca = round((float) $row->ca, 2);
                $nb = (int) $row->nombre_commandes;

                return [
                    'point_de_vente_id' => (int) $row->point_de_vente_id,
                    'point_de_vente' => $row->point_de_vente,
                    'ca' => $ca,
                    'nombre_commandes' => $nb,
                    'panier_moyen' => $nb > 0 ? round($ca / $nb, 2) : 0.0,
                ];
            })
            ->all();
    }

    /**
     * Top / flop produits. Tri configurable (ca|quantite), ordre et limite.
     *
     * @param  int[]|null  $siteIds
     * @return array<int, array<string, mixed>>
     */
    public function produits(Periode $periode, ?array $siteIds, ?int $pdvId, string $tri = 'ca', string $ordre = 'desc', int $limit = 20): array
    {
        $caTotal = (float) $this->lignesVenduesQuery($periode, $siteIds, $pdvId)
            ->selectRaw('COALESCE(SUM(l.quantite * l.prix_unitaire), 0) as ca')
            ->value('ca');

        $colonneTri = $tri === 'quantite' ? 'quantite_vendue' : 'ca';
        $sens = $ordre === 'asc' ? 'asc' : 'desc';

        return $this->lignesVenduesQuery($periode, $siteIds, $pdvId)
            ->join('produits as pr', 'pr.id', '=', 'l.produit_id')
            ->groupBy('l.produit_id', 'pr.libelle')
            ->selectRaw(
                'l.produit_id, pr.libelle as produit, '.
                'SUM(l.quantite) as quantite_vendue, '.
                'SUM(l.quantite * l.prix_unitaire) as ca'
            )
            ->orderByRaw("{$colonneTri} {$sens}")
            ->limit($limit)
            ->get()
            ->map(fn ($row) => [
                'produit_id' => (int) $row->produit_id,
                'produit' => $row->produit,
                'quantite_vendue' => (float) $row->quantite_vendue,
                'ca' => round((float) $row->ca, 2),
                'part_ca_pct' => $caTotal > 0 ? round((float) $row->ca / $caTotal * 100, 2) : 0.0,
            ])
            ->all();
    }

    /**
     * CA par mode de paiement — VUE TRÉSORERIE : flux réellement encaissés
     * (table `paiements` des commandes au statut `encaissee`), période sur la
     * date de la commande.
     *
     * @param  int[]|null  $siteIds
     * @return array<int, array<string, mixed>>
     */
    public function modesPaiement(Periode $periode, ?array $siteIds, ?int $pdvId): array
    {
        $base = fn (): Builder => DB::table('paiements as pay')
            ->join('commandes_client as cc', 'cc.id', '=', 'pay.commande_client_id')
            ->join('tables_salle as t', 't.id', '=', 'cc.table_id')
            ->join('points_de_vente as pdv', 'pdv.id', '=', 't.point_de_vente_id')
            ->where('cc.statut', 'encaissee')
            ->whereBetween('cc.date_heure', [$periode->debut, $periode->fin])
            ->when($siteIds !== null, fn (Builder $q) => $q->whereIn('pdv.site_id', $siteIds))
            ->when($pdvId !== null, fn (Builder $q) => $q->where('pdv.id', $pdvId));

        $total = (float) $base()->sum('pay.montant');

        return $base()
            ->join('modes_de_paiement as mdp', 'mdp.id', '=', 'pay.mode_de_paiement_id')
            ->groupBy('mdp.id', 'mdp.libelle')
            ->selectRaw('mdp.id as mode_de_paiement_id, mdp.libelle as mode, SUM(pay.montant) as montant_total, COUNT(*) as nombre_paiements')
            ->get()
            ->map(fn ($row) => [
                'mode_de_paiement_id' => (int) $row->mode_de_paiement_id,
                'mode' => $row->mode,
                'montant_total' => round((float) $row->montant_total, 2),
                'nombre_paiements' => (int) $row->nombre_paiements,
                'part_pct' => $total > 0 ? round((float) $row->montant_total / $total * 100, 2) : 0.0,
            ])
            ->all();
    }

    /**
     * CA par serveur (user de la commande).
     *
     * @param  int[]|null  $siteIds
     * @return array<int, array<string, mixed>>
     */
    public function serveurs(Periode $periode, ?array $siteIds, ?int $pdvId): array
    {
        return $this->commandesVenduesQuery($periode, $siteIds, $pdvId)
            ->join('users as u', 'u.id', '=', 'cc.user_id')
            ->leftJoin('lignes_commande_client as l', function ($join) {
                $join->on('l.commande_client_id', '=', 'cc.id')->where('l.statut', '=', 'actif');
            })
            ->groupBy('cc.user_id', 'u.nom', 'u.prenom')
            ->selectRaw(
                'cc.user_id, u.nom, u.prenom, '.
                'COUNT(DISTINCT cc.id) as nombre_commandes, '.
                'COALESCE(SUM(l.quantite * l.prix_unitaire), 0) as ca'
            )
            ->get()
            ->map(function ($row) {
                $ca = round((float) $row->ca, 2);
                $nb = (int) $row->nombre_commandes;

                return [
                    'user_id' => $row->user_id,
                    'serveur' => trim("{$row->prenom} {$row->nom}"),
                    'ca' => $ca,
                    'nombre_commandes' => $nb,
                    'panier_moyen' => $nb > 0 ? round($ca / $nb, 2) : 0.0,
                ];
            })
            ->all();
    }

    /**
     * Série temporelle pour graphes. Granularité : jour→heures (24),
     * semaine→jours (7), mois→jours, plage→jours. Chaque point inclut les
     * intervalles sans vente (CA 0) pour une courbe continue.
     *
     * @param  int[]|null  $siteIds
     * @return array<int, array{label: string, ca: float, nombre_commandes: int}>
     */
    public function evolution(Periode $periode, ?array $siteIds, ?int $pdvId): array
    {
        $commandes = $this->commandesVenduesQuery($periode, $siteIds, $pdvId)
            ->leftJoin('lignes_commande_client as l', function ($join) {
                $join->on('l.commande_client_id', '=', 'cc.id')->where('l.statut', '=', 'actif');
            })
            ->groupBy('cc.id', 'cc.date_heure')
            ->selectRaw('cc.id, cc.date_heure, COALESCE(SUM(l.quantite * l.prix_unitaire), 0) as ca')
            ->get();

        $parHeure = $periode->type === 'jour';
        $buckets = $this->bucketsVides($periode, $parHeure);

        foreach ($commandes as $commande) {
            $date = Carbon::parse($commande->date_heure);
            $cle = $parHeure ? $date->format('H') : $date->format('Y-m-d');

            if (! isset($buckets[$cle])) {
                continue;
            }

            $buckets[$cle]['ca'] += (float) $commande->ca;
            $buckets[$cle]['nombre_commandes']++;
        }

        return array_map(fn (array $point) => [
            'label' => $point['label'],
            'ca' => round($point['ca'], 2),
            'nombre_commandes' => $point['nombre_commandes'],
        ], array_values($buckets));
    }

    /**
     * Squelette des intervalles (clé => label/ca/nb), bornes incluses.
     *
     * @return array<string, array{label: string, ca: float, nombre_commandes: int}>
     */
    private function bucketsVides(Periode $periode, bool $parHeure): array
    {
        $buckets = [];

        if ($parHeure) {
            for ($h = 0; $h < 24; $h++) {
                $cle = str_pad((string) $h, 2, '0', STR_PAD_LEFT);
                $buckets[$cle] = ['label' => $cle.'h', 'ca' => 0.0, 'nombre_commandes' => 0];
            }

            return $buckets;
        }

        $jour = $periode->debut->copy()->startOfDay();
        $fin = $periode->fin->copy()->startOfDay();

        while ($jour->lessThanOrEqualTo($fin)) {
            $cle = $jour->format('Y-m-d');
            $buckets[$cle] = ['label' => $cle, 'ca' => 0.0, 'nombre_commandes' => 0];
            $jour->addDay();
        }

        return $buckets;
    }
}
