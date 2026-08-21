<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\DestinationVersement;
use App\Models\Paiement;
use App\Models\Versement;
use Illuminate\Support\Collection;

/**
 * Ce qui reste dû à chacun sur les pourboires encaissés au comptoir.
 *
 * Un SOLDE et non un instantané : le rapport de fermeture disait ce qu'une
 * session avait généré, ce qui ne sert à rien trois jours plus tard si personne
 * n'a reversé. La dette court dans le temps, à travers les sessions, jusqu'à ce
 * qu'un versement l'éteigne.
 *
 *     dû = Σ pourboires des notes du serveur − Σ reversements qu'il a reçus
 *
 * Rien n'oblige à reverser avant de fermer : un pourboire qui dort une nuit
 * dans le tiroir est compté et entre dans le théorique, l'écart reste juste. Ce
 * qui serait grave, c'est qu'il se perde de vue — d'où cette lecture.
 */
class PourboireService
{
    /**
     * @param  int[]|null  $siteIds  Périmètre de sites, ou null pour tout voir.
     * @return array<int, array{user_id: string, serveur: string, encaisse: float, reverse: float, solde: float}>
     */
    public function soldesParServeur(?array $siteIds): array
    {
        $encaisses = $this->pourboiresEncaisses($siteIds);
        $reverses = $this->reversementsRecus($siteIds);

        $lignes = [];

        foreach ($encaisses as $userId => $ligne) {
            $reverse = round((float) ($reverses[$userId]['montant'] ?? 0), 2);

            $lignes[] = [
                'user_id' => (string) $userId,
                'serveur' => (string) $ligne->serveur,
                'encaisse' => round((float) $ligne->montant, 2),
                'reverse' => $reverse,
                'solde' => round((float) $ligne->montant - $reverse, 2),
            ];
        }

        // Un serveur qui a reçu plus qu'il n'a généré ne doit pas disparaître de
        // la liste : son solde négatif est une anomalie, elle doit se voir.
        foreach ($reverses as $userId => $ligne) {
            if (! isset($encaisses[$userId])) {
                $lignes[] = [
                    'user_id' => (string) $userId,
                    'serveur' => (string) $ligne->serveur,
                    'encaisse' => 0.0,
                    'reverse' => round((float) $ligne->montant, 2),
                    'solde' => round(-(float) $ligne->montant, 2),
                ];
            }
        }

        usort($lignes, fn (array $a, array $b): int => $b['solde'] <=> $a['solde']);

        return $lignes;
    }

    /**
     * Pourboires générés par les notes de chaque serveur. Le bénéficiaire est
     * `commandes_client.user_id` — celui qui tenait la note.
     *
     * @param  int[]|null  $siteIds
     */
    private function pourboiresEncaisses(?array $siteIds): Collection
    {
        return Paiement::query()
            ->join('commandes_client as cc', 'cc.id', '=', 'paiements.commande_client_id')
            ->join('users as u', 'u.id', '=', 'cc.user_id')
            ->join('tables_salle as t', 't.id', '=', 'cc.table_id')
            ->join('points_de_vente as pdv', 'pdv.id', '=', 't.point_de_vente_id')
            ->where('paiements.pourboire', '>', 0)
            ->when($siteIds !== null, fn ($q) => $q->whereIn('pdv.site_id', $siteIds))
            ->groupBy('u.id', 'u.prenom', 'u.nom')
            ->selectRaw("u.id as user_id, TRIM(CONCAT(u.prenom, ' ', u.nom)) as serveur, SUM(paiements.pourboire) as montant")
            ->get()
            ->keyBy('user_id');
    }

    /**
     * Reversements déjà reçus. Seule la destination « personnel » compte : un
     * dépôt au coffre n'éteint la dette de personne.
     *
     * @param  int[]|null  $siteIds
     */
    private function reversementsRecus(?array $siteIds): Collection
    {
        return Versement::query()
            ->join('users as u', 'u.id', '=', 'versements.beneficiaire_id')
            ->join('caisses as c', 'c.id', '=', 'versements.caisse_id')
            ->join('points_de_vente as pdv', 'pdv.id', '=', 'c.point_de_vente_id')
            ->where('versements.destination', DestinationVersement::Personnel->value)
            ->when($siteIds !== null, fn ($q) => $q->whereIn('pdv.site_id', $siteIds))
            ->groupBy('u.id', 'u.prenom', 'u.nom')
            ->selectRaw("u.id as user_id, TRIM(CONCAT(u.prenom, ' ', u.nom)) as serveur, SUM(versements.montant) as montant")
            ->get()
            ->keyBy('user_id');
    }
}
