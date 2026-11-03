<?php

declare(strict_types=1);

namespace App\Services\Reporting;

use App\Enums\StatutSessionCaisse;
use App\Models\SessionCaisse;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Où en est l'argent, par opposition à où en est la marge (lecture seule).
 *
 * Le reste du reporting répond à « qu'avons-nous vendu ». Celui-ci répond à
 * « qu'avons-nous », ce qu'aucun écran ne savait dire : le théorique d'une
 * caisse n'existait qu'à la fermeture, et rien n'agrégeait les tiroirs.
 *
 * Tout y est calculé en requêtes GROUPÉES et jamais en bouclant sur les
 * sessions : {@see SessionCaisse::theoriqueParMode()} coûte trois
 * requêtes par session, ce qui est juste pour une session et intenable pour un
 * consolidé.
 */
class TresorerieReportService
{
    /**
     * Position consolidée du périmètre : ce qui dort dans les tiroirs.
     *
     * Deux sources, qui ne se recouvrent pas :
     *  - les sessions OUVERTES, dont le théorique se recalcule ;
     *  - le reliquat des dernières sessions FERMÉES d'une caisse au repos, soit
     *    ce qui a été compté moins ce qui en est sorti depuis.
     *
     * @param  int[]|null  $siteIds
     * @return array<string, mixed>
     */
    public function position(?array $siteIds): array
    {
        $sessions = $this->sessionsOuvertes($siteIds);
        $ids = array_column($sessions, 'id');

        $enTiroir = $this->paiementsParSession($ids, enTiroir: true);
        $horsTiroir = $this->paiementsParSession($ids, enTiroir: false);
        $pourboires = $this->pourboiresParSession($ids);
        $verses = $this->versementsEnServiceParSession($ids);

        $caisses = [];
        $totalTiroir = 0.0;
        $totalHorsTiroir = 0.0;
        $totalPourboires = 0.0;

        foreach ($sessions as $session) {
            $id = $session['id'];
            $tiroir = round((float) $session['fonds_initial'] + ($enTiroir[$id] ?? 0.0) - ($verses[$id] ?? 0.0), 2);

            $totalTiroir += $tiroir;
            $totalHorsTiroir += $horsTiroir[$id] ?? 0.0;
            $totalPourboires += $pourboires[$id] ?? 0.0;

            $caisses[] = [
                'session_caisse_id' => $id,
                'caisse_id' => $session['caisse_id'],
                'caisse' => $session['caisse'],
                'point_de_vente' => $session['point_de_vente'],
                'site_id' => $session['site_id'],
                'ouverte_le' => $session['ouvert_le'],
                'caissier' => trim(($session['prenom'] ?? '').' '.($session['nom'] ?? '')),
                'en_tiroir' => $tiroir,
                'hors_tiroir' => round($horsTiroir[$id] ?? 0.0, 2),
                'pourboires_dus' => round($pourboires[$id] ?? 0.0, 2),
            ];
        }

        $repos = $this->reliquatDesCaissesAuRepos($siteIds);

        return [
            'caisses_ouvertes' => $caisses,
            'caisses_au_repos' => $repos['caisses'],
            'totaux' => [
                'en_service' => round($totalTiroir, 2),
                'au_repos' => $repos['total'],
                // Ce qu'on a, tiroirs confondus. C'est la réponse à « combien
                // avons-nous, là, maintenant ».
                'en_caisse' => round($totalTiroir + $repos['total'], 2),
                'hors_tiroir_en_service' => round($totalHorsTiroir, 2),
                // Physiquement dans les tiroirs, mais dû aux serveurs : à
                // retrancher pour savoir de quoi l'établissement dispose.
                'pourboires_dus' => round($totalPourboires, 2),
                'disponible' => round($totalTiroir + $repos['total'] - $totalPourboires, 2),
            ],
            // Une session fermée sans que le tiroir ait été compté n'a pas de
            // reliquat CONNU. La compter pour zéro annoncerait une caisse vide
            // là où personne n'a regardé : on l'isole et on la signale, comme le
            // food cost isole ses lignes non valorisées.
            'non_comptees' => $repos['non_comptees'],
        ];
    }

    /**
     * Sessions ouvertes du périmètre, avec leur caisse et leur caissier.
     *
     * @param  int[]|null  $siteIds
     * @return array<int, array<string, mixed>>
     */
    private function sessionsOuvertes(?array $siteIds): array
    {
        return DB::table('sessions_caisse as s')
            ->join('caisses as c', 'c.id', '=', 's.caisse_id')
            ->join('points_de_vente as pdv', 'pdv.id', '=', 'c.point_de_vente_id')
            ->leftJoin('users as u', 'u.id', '=', 's.user_id')
            ->where('s.statut', StatutSessionCaisse::Ouverte->value)
            ->when($siteIds !== null, fn (Builder $q) => $q->whereIn('pdv.site_id', $siteIds))
            ->orderBy('pdv.site_id')
            ->orderBy('c.id')
            ->selectRaw(
                's.id, s.caisse_id, s.fonds_initial, s.ouvert_le, '.
                'c.libelle as caisse, pdv.nom as point_de_vente, pdv.site_id, '.
                'u.prenom, u.nom'
            )
            ->get()
            ->map(fn ($row): array => (array) $row)
            ->all();
    }

    /**
     * Σ (montant + pourboire) par session, selon que le mode alimente ou non le
     * tiroir. Une seule requête pour toutes les sessions.
     *
     * @param  int[]  $sessionIds
     * @return array<int, float>
     */
    private function paiementsParSession(array $sessionIds, bool $enTiroir): array
    {
        if ($sessionIds === []) {
            return [];
        }

        return DB::table('paiements as p')
            ->join('commandes_client as cc', 'cc.id', '=', 'p.commande_client_id')
            ->join('modes_de_paiement as m', 'm.id', '=', 'p.mode_de_paiement_id')
            ->whereIn('cc.session_caisse_id', $sessionIds)
            ->where('m.compte_en_caisse', $enTiroir)
            ->groupBy('cc.session_caisse_id')
            ->selectRaw('cc.session_caisse_id, SUM(p.montant + p.pourboire) as total')
            ->pluck('total', 'session_caisse_id')
            ->map(fn ($total): float => round((float) $total, 2))
            ->all();
    }

    /**
     * @param  int[]  $sessionIds
     * @return array<int, float>
     */
    private function pourboiresParSession(array $sessionIds): array
    {
        if ($sessionIds === []) {
            return [];
        }

        return DB::table('paiements as p')
            ->join('commandes_client as cc', 'cc.id', '=', 'p.commande_client_id')
            ->whereIn('cc.session_caisse_id', $sessionIds)
            ->groupBy('cc.session_caisse_id')
            ->selectRaw('cc.session_caisse_id, SUM(p.pourboire) as total')
            ->pluck('total', 'session_caisse_id')
            ->map(fn ($total): float => round((float) $total, 2))
            ->all();
    }

    /**
     * @param  int[]  $sessionIds
     * @return array<int, float>
     */
    private function versementsEnServiceParSession(array $sessionIds): array
    {
        if ($sessionIds === []) {
            return [];
        }

        return DB::table('versements')
            ->whereIn('session_caisse_id', $sessionIds)
            ->where('apres_fermeture', false)
            ->groupBy('session_caisse_id')
            ->selectRaw('session_caisse_id, SUM(montant) as total')
            ->pluck('total', 'session_caisse_id')
            ->map(fn ($total): float => round((float) $total, 2))
            ->all();
    }

    /**
     * Ce qui reste dans les tiroirs des caisses SANS session ouverte.
     *
     * On part du dernier compte constaté et on retranche ce qui est sorti après
     * la fermeture : c'est ce que la caisse suivante devrait retrouver en
     * s'ouvrant, et donc de l'argent qu'on détient toujours.
     *
     * @param  int[]|null  $siteIds
     * @return array{caisses: array<int, array<string, mixed>>, total: float, non_comptees: array<int, array<string, mixed>>}
     */
    private function reliquatDesCaissesAuRepos(?array $siteIds): array
    {
        $dernieres = DB::table('sessions_caisse as s')
            ->join('caisses as c', 'c.id', '=', 's.caisse_id')
            ->join('points_de_vente as pdv', 'pdv.id', '=', 'c.point_de_vente_id')
            ->where('s.statut', StatutSessionCaisse::Fermee->value)
            ->when($siteIds !== null, fn (Builder $q) => $q->whereIn('pdv.site_id', $siteIds))
            // Une caisse qui a rouvert n'a plus de reliquat : son argent est
            // reparti dans la session en cours, déjà comptée plus haut.
            ->whereNotExists(fn (Builder $q) => $q->from('sessions_caisse as o')
                ->whereColumn('o.caisse_id', 's.caisse_id')
                ->where('o.statut', StatutSessionCaisse::Ouverte->value)
            )
            // La dernière fermée de chaque caisse, et elle seule.
            ->whereNotExists(fn (Builder $q) => $q->from('sessions_caisse as plus_recente')
                ->whereColumn('plus_recente.caisse_id', 's.caisse_id')
                ->where('plus_recente.statut', StatutSessionCaisse::Fermee->value)
                ->whereColumn('plus_recente.id', '>', 's.id')
            )
            ->selectRaw(
                's.id, s.caisse_id, s.montant_reel, s.ferme_le, '.
                'c.libelle as caisse, pdv.nom as point_de_vente, pdv.site_id'
            )
            ->get();

        $sorties = $this->versementsApresFermetureParSession($dernieres->pluck('id')->all());

        $caisses = [];
        $nonComptees = [];
        $total = 0.0;

        foreach ($dernieres as $ligne) {
            $entree = [
                'session_caisse_id' => (int) $ligne->id,
                'caisse_id' => (int) $ligne->caisse_id,
                'caisse' => $ligne->caisse,
                'point_de_vente' => $ligne->point_de_vente,
                'site_id' => (int) $ligne->site_id,
                'fermee_le' => $ligne->ferme_le,
            ];

            if ($ligne->montant_reel === null) {
                $nonComptees[] = $entree;

                continue;
            }

            $reste = round((float) $ligne->montant_reel - ($sorties[(int) $ligne->id] ?? 0.0), 2);
            $total += $reste;
            $caisses[] = [...$entree, 'reste_en_tiroir' => $reste];
        }

        return ['caisses' => $caisses, 'total' => round($total, 2), 'non_comptees' => $nonComptees];
    }

    /**
     * @param  int[]  $sessionIds
     * @return array<int, float>
     */
    private function versementsApresFermetureParSession(array $sessionIds): array
    {
        if ($sessionIds === []) {
            return [];
        }

        return DB::table('versements')
            ->whereIn('session_caisse_id', $sessionIds)
            ->where('apres_fermeture', true)
            ->groupBy('session_caisse_id')
            ->selectRaw('session_caisse_id, SUM(montant) as total')
            ->pluck('total', 'session_caisse_id')
            ->map(fn ($total): float => round((float) $total, 2))
            ->all();
    }
}
