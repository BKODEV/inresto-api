<?php

declare(strict_types=1);

namespace App\Services\Reporting;

use App\Support\Periode;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Rapports de stock (lecture seule). Deux natures :
 *  - photo instantanée (valorisation, alertes) : ignore la période ;
 *  - flux sur période (consommation, écarts d'inventaire) : valorisés au pmp.
 */
class StockReportService
{
    /**
     * @var string[] Mouvements de sortie pris en compte dans la consommation.
     */
    private array $typesSortie = ['sortie_vente', 'perte', 'casse', 'offert', 'repas_personnel'];

    /**
     * Valorisation du stock courant par site (photo instantanée).
     *
     * @param  int[]|null  $siteIds
     * @return array{sites: array<int, array<string, mixed>>, total_valorise: float}
     */
    public function valorisation(?array $siteIds): array
    {
        $rows = DB::table('stocks as s')
            ->join('matieres_premieres as mp', 'mp.id', '=', 's.matiere_premiere_id')
            ->join('sites as si', 'si.id', '=', 's.site_id')
            ->leftJoin('unites_de_mesure as um', 'um.id', '=', 'mp.unite_de_mesure_id')
            ->when($siteIds !== null, fn (Builder $q) => $q->whereIn('s.site_id', $siteIds))
            ->orderBy('si.nom')
            ->orderBy('mp.libelle')
            ->selectRaw(
                's.site_id, si.nom as site, s.matiere_premiere_id, mp.libelle as matiere, '.
                's.stock_courant, s.pmp, (s.stock_courant * s.pmp) as valeur, um.symbole as unite'
            )
            ->get();

        $sites = [];
        $totalGlobal = 0.0;

        foreach ($rows as $row) {
            $siteId = (int) $row->site_id;
            $valeur = round((float) $row->valeur, 2);
            $totalGlobal += $valeur;

            $sites[$siteId] ??= [
                'site_id' => $siteId,
                'site' => $row->site,
                'matieres' => [],
                'total_valorise' => 0.0,
            ];

            $sites[$siteId]['matieres'][] = [
                'matiere_premiere_id' => (int) $row->matiere_premiere_id,
                'matiere' => $row->matiere,
                'stock_courant' => (float) $row->stock_courant,
                'pmp' => round((float) $row->pmp, 4),
                'valeur' => $valeur,
                'unite' => $row->unite,
            ];
            $sites[$siteId]['total_valorise'] = round($sites[$siteId]['total_valorise'] + $valeur, 2);
        }

        return [
            'sites' => array_values($sites),
            'total_valorise' => round($totalGlobal, 2),
        ];
    }

    /**
     * Consommation matière sur la période : sorties valorisées au pmp figé,
     * avec ventilation par type. Tri par valeur décroissante.
     *
     * @param  int[]|null  $siteIds
     * @return array<int, array<string, mixed>>
     */
    public function consommation(Periode $periode, ?array $siteIds): array
    {
        $rows = DB::table('mouvements_stock as m')
            ->join('matieres_premieres as mp', 'mp.id', '=', 'm.matiere_premiere_id')
            ->leftJoin('unites_de_mesure as um', 'um.id', '=', 'mp.unite_de_mesure_id')
            ->whereIn('m.type', $this->typesSortie)
            ->whereBetween('m.date_mouvement', [$periode->debut, $periode->fin])
            ->when($siteIds !== null, fn (Builder $q) => $q->whereIn('m.site_id', $siteIds))
            ->groupBy('m.matiere_premiere_id', 'mp.libelle', 'um.symbole', 'm.type')
            ->selectRaw(
                'm.matiere_premiere_id, mp.libelle as matiere, um.symbole as unite, m.type, '.
                'SUM(m.quantite) as quantite, SUM(m.quantite * m.pmp_au_moment) as valeur'
            )
            ->get();

        $matieres = [];

        foreach ($rows as $row) {
            $id = (int) $row->matiere_premiere_id;
            $quantite = (float) $row->quantite;
            $valeur = round((float) $row->valeur, 2);

            $matieres[$id] ??= [
                'matiere_premiere_id' => $id,
                'matiere' => $row->matiere,
                'unite' => $row->unite,
                'quantite_sortie' => 0.0,
                'valeur_sortie' => 0.0,
                'ventilation' => [],
            ];

            $matieres[$id]['quantite_sortie'] = round($matieres[$id]['quantite_sortie'] + $quantite, 3);
            $matieres[$id]['valeur_sortie'] = round($matieres[$id]['valeur_sortie'] + $valeur, 2);
            $matieres[$id]['ventilation'][$row->type] = [
                'quantite' => $quantite,
                'valeur' => $valeur,
            ];
        }

        usort($matieres, fn ($a, $b) => $b['valeur_sortie'] <=> $a['valeur_sortie']);

        return array_values($matieres);
    }

    /**
     * Écarts d'inventaire sur la période (inventaires clôturés). Valorisation au
     * pmp courant de la matière sur le site. `detail` ajoute le détail par matière.
     *
     * @param  int[]|null  $siteIds
     * @return array<int, array<string, mixed>>
     */
    public function ecartsInventaire(Periode $periode, ?array $siteIds, bool $detail = false): array
    {
        $inventaires = DB::table('inventaires as inv')
            ->join('sites as si', 'si.id', '=', 'inv.site_id')
            ->where('inv.statut', 'cloture')
            // Bornes en date-heure (et non en chaîne « Y-m-d ») : sur un moteur
            // sans type date natif, la comparaison est lexicographique et
            // « 2026-07-31 00:00:00 » > « 2026-07-31 » exclurait le dernier jour.
            ->whereBetween('inv.date_inventaire', [$periode->debut->copy()->startOfDay(), $periode->fin->copy()->endOfDay()])
            ->when($siteIds !== null, fn (Builder $q) => $q->whereIn('inv.site_id', $siteIds))
            ->orderByDesc('inv.date_inventaire')
            ->selectRaw('inv.id, inv.site_id, si.nom as site, inv.date_inventaire')
            ->get();

        if ($inventaires->isEmpty()) {
            return [];
        }

        // Nombre de matières référencées au stock par site (dénominateur du taux de couverture).
        $matieresParSite = DB::table('stocks')
            ->whereIn('site_id', $inventaires->pluck('site_id')->unique()->all())
            ->groupBy('site_id')
            ->selectRaw('site_id, COUNT(*) as total')
            ->pluck('total', 'site_id');

        // Lignes valorisées au pmp courant (matière, site de l'inventaire).
        $lignes = DB::table('lignes_inventaire as li')
            ->join('inventaires as inv', 'inv.id', '=', 'li.inventaire_id')
            ->join('matieres_premieres as mp', 'mp.id', '=', 'li.matiere_premiere_id')
            ->leftJoin('stocks as s', function ($join) {
                $join->on('s.matiere_premiere_id', '=', 'li.matiere_premiere_id')
                    ->on('s.site_id', '=', 'inv.site_id');
            })
            ->whereIn('li.inventaire_id', $inventaires->pluck('id')->all())
            ->selectRaw(
                'li.inventaire_id, li.matiere_premiere_id, mp.libelle as matiere, '.
                'li.ecart, COALESCE(s.pmp, 0) as pmp, (li.ecart * COALESCE(s.pmp, 0)) as ecart_valeur'
            )
            ->get()
            ->groupBy('inventaire_id');

        return $inventaires->map(function ($inv) use ($lignes, $matieresParSite, $detail) {
            $lignesInv = $lignes->get($inv->id, collect());
            $ecartTotal = 0.0;
            $demarque = 0.0;

            foreach ($lignesInv as $ligne) {
                $valeur = (float) $ligne->ecart_valeur;
                $ecartTotal += $valeur;
                if ($valeur < 0) {
                    $demarque += abs($valeur);
                }
            }

            $nbLignes = $lignesInv->count();
            $denominateur = (int) ($matieresParSite[$inv->site_id] ?? 0);

            $resultat = [
                'inventaire_id' => (int) $inv->id,
                'site_id' => (int) $inv->site_id,
                'site' => $inv->site,
                'date' => $inv->date_inventaire,
                'nb_lignes' => $nbLignes,
                'ecart_total_valeur' => round($ecartTotal, 2),
                'demarque_valeur' => round($demarque, 2),
                'taux_couverture' => $denominateur > 0 ? round($nbLignes / $denominateur * 100, 2) : 0.0,
            ];

            if ($detail) {
                $resultat['matieres'] = $lignesInv->map(fn ($ligne) => [
                    'matiere_premiere_id' => (int) $ligne->matiere_premiere_id,
                    'matiere' => $ligne->matiere,
                    'ecart' => (float) $ligne->ecart,
                    'pmp' => round((float) $ligne->pmp, 4),
                    'ecart_valeur' => round((float) $ligne->ecart_valeur, 2),
                ])->values()->all();
            }

            return $resultat;
        })->all();
    }

    /**
     * Matières sous leur seuil d'alerte (photo instantanée).
     *
     * @param  int[]|null  $siteIds
     * @return array<int, array<string, mixed>>
     */
    public function alertes(?array $siteIds): array
    {
        return DB::table('stocks as s')
            ->join('matieres_premieres as mp', 'mp.id', '=', 's.matiere_premiere_id')
            ->join('sites as si', 'si.id', '=', 's.site_id')
            ->leftJoin('unites_de_mesure as um', 'um.id', '=', 'mp.unite_de_mesure_id')
            ->where('mp.seuil_alerte_stock', '>', 0)
            ->whereColumn('s.stock_courant', '<=', 'mp.seuil_alerte_stock')
            ->when($siteIds !== null, fn (Builder $q) => $q->whereIn('s.site_id', $siteIds))
            ->orderBy('si.nom')
            ->orderBy('mp.libelle')
            ->selectRaw(
                's.site_id, si.nom as site, s.matiere_premiere_id, mp.libelle as matiere, '.
                's.stock_courant, mp.seuil_alerte_stock as seuil, '.
                '(mp.seuil_alerte_stock - s.stock_courant) as manquant, um.symbole as unite'
            )
            ->get()
            ->map(fn ($row) => [
                'matiere_premiere_id' => (int) $row->matiere_premiere_id,
                'matiere' => $row->matiere,
                'site_id' => (int) $row->site_id,
                'site' => $row->site,
                'stock_courant' => (float) $row->stock_courant,
                'seuil' => (float) $row->seuil,
                'manquant' => round((float) $row->manquant, 3),
                'unite' => $row->unite,
            ])
            ->all();
    }
}
