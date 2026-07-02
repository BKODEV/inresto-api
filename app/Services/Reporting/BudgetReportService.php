<?php

declare(strict_types=1);

namespace App\Services\Reporting;

use App\Models\Budget;
use App\Support\Periode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Rapports budget (lecture seule). Réutilise les calculs du modèle {@see Budget}
 * (montant consommé, restant, %, niveau d'alerte) — aucune réimplémentation.
 */
class BudgetReportService
{
    /**
     * Budgets concernés par la période, prêts pour la BudgetResource (agrégat
     * `depenses_sum_montant` préchargé pour éviter le N+1).
     *
     * @param  int[]|null  $siteIds
     * @return Collection<int, Budget>
     */
    public function consommation(Periode $periode, ?array $siteIds): Collection
    {
        return Budget::query()
            ->with('site')
            ->withSum('depenses', 'montant')
            ->when($siteIds !== null, fn (Builder $q) => $q->whereIn('site_id', $siteIds))
            ->whereBetween('mois', [$periode->debut->copy()->startOfDay(), $periode->fin])
            ->orderByDesc('mois')
            ->get();
    }

    /**
     * Dépenses détaillées de la période, ventilées par type avec totaux.
     *
     * @param  int[]|null  $siteIds
     * @return array{depenses: array<int, array<string, mixed>>, totaux_par_type: array<string, float>, total: float}
     */
    public function depenses(Periode $periode, ?array $siteIds): array
    {
        $depenses = DB::table('depenses as d')
            ->join('budgets as b', 'b.id', '=', 'd.budget_id')
            ->whereBetween('d.date_depense', [$periode->debut->toDateString(), $periode->fin->toDateString()])
            ->when($siteIds !== null, fn (QueryBuilder $q) => $q->whereIn('b.site_id', $siteIds))
            ->orderBy('d.date_depense')
            ->selectRaw('d.id, b.site_id, d.budget_id, d.reception_id, d.type, d.libelle, d.montant, d.date_depense')
            ->get();

        $totauxParType = ['achat_fournisseur' => 0.0, 'charge_hors_stock' => 0.0];
        $total = 0.0;

        $lignes = $depenses->map(function ($d) use (&$totauxParType, &$total) {
            $montant = round((float) $d->montant, 2);
            $totauxParType[$d->type] = round(($totauxParType[$d->type] ?? 0.0) + $montant, 2);
            $total = round($total + $montant, 2);

            return [
                'id' => (int) $d->id,
                'site_id' => (int) $d->site_id,
                'budget_id' => (int) $d->budget_id,
                'reception_id' => $d->reception_id !== null ? (int) $d->reception_id : null,
                'type' => $d->type,
                'libelle' => $d->libelle,
                'montant' => $montant,
                'date_depense' => $d->date_depense,
            ];
        })->all();

        return [
            'depenses' => $lignes,
            'totaux_par_type' => $totauxParType,
            'total' => $total,
        ];
    }
}
