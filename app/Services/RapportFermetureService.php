<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\StatutCommandeClient;
use App\Models\Annulation;
use App\Models\CommandeClient;
use App\Models\Impression;
use App\Models\Paiement;
use App\Models\Remise;
use App\Models\SessionCaisse;
use Illuminate\Support\Facades\DB;

/**
 * Assemble le rapport de fermeture d'une session de caisse — le « ticket Z ».
 *
 * Service de LECTURE : il n'écrit rien et ne fige rien. Les chiffres du
 * rapprochement (théorique, compté, écart) sont déjà gelés en colonnes par
 * {@see SessionCaisseService::fermer()} ; ce qui est recalculé ici est le
 * détail qui les explique, et qui n'a pas à être dupliqué en base.
 *
 * Le rapport répond à trois questions, dans cet ordre :
 *   — qu'a-t-on vendu (le chiffre d'affaires, lu sur les lignes) ;
 *   — que doit contenir le tiroir, et que contient-il (le rapprochement) ;
 *   — quels gestes sensibles ont eu lieu pendant le service.
 */
class RapportFermetureService
{
    /**
     * @return array<string, mixed>
     */
    public function pour(SessionCaisse $session): array
    {
        $session->loadMissing(['caisse.pointDeVente.site', 'user', 'fermePar']);

        $tolerance = (float) ($session->caisse?->pointDeVente?->site?->parametreEtablissement
            ?->tolerance_ecart_caisse ?? 0);

        return [
            'session' => $session,
            'tolerance' => $tolerance,
            'ecart_sens' => $session->sensEcart($tolerance),
            'notes_encaissees' => $this->nombreNotesEncaissees($session),
            'chiffre_affaires' => $this->chiffreAffaires($session),
            'modes' => $this->encaisseParMode($session),
            'total_pourboires' => $session->totalPourboires(),
            'pourboires_par_serveur' => $session->pourboiresParServeur(),
            'gestes' => $this->gestesSensibles($session),
        ];
    }

    private function nombreNotesEncaissees(SessionCaisse $session): int
    {
        return $session->commandes()
            ->where('statut', StatutCommandeClient::Encaissee->value)
            ->count();
    }

    /**
     * Chiffre d'affaires de la session : les LIGNES actives des notes
     * encaissées, jamais les paiements. Un pourboire n'est pas une vente, et un
     * règlement hors tiroir reste du chiffre d'affaires.
     */
    private function chiffreAffaires(SessionCaisse $session): float
    {
        return round((float) DB::table('lignes_commande_client as l')
            ->join('commandes_client as cc', 'cc.id', '=', 'l.commande_client_id')
            ->where('cc.session_caisse_id', $session->id)
            ->where('cc.statut', StatutCommandeClient::Encaissee->value)
            ->where('l.statut', 'actif')
            ->sum(DB::raw('l.quantite * l.prix_unitaire')), 2);
    }

    /**
     * Encaissé par mode, recette et pourboire séparés, en signalant lesquels
     * finissent dans le tiroir compté — c'est la ligne de partage du
     * rapprochement.
     *
     * @return array<int, array{mode: string, compte_en_caisse: bool, montant: float, pourboire: float}>
     */
    private function encaisseParMode(SessionCaisse $session): array
    {
        return Paiement::query()
            ->join('commandes_client as cc', 'cc.id', '=', 'paiements.commande_client_id')
            ->join('modes_de_paiement as mdp', 'mdp.id', '=', 'paiements.mode_de_paiement_id')
            ->where('cc.session_caisse_id', $session->id)
            ->groupBy('mdp.id', 'mdp.libelle', 'mdp.compte_en_caisse', 'mdp.ordre')
            ->orderByDesc('mdp.compte_en_caisse')
            ->orderBy('mdp.ordre')
            ->selectRaw('mdp.libelle as mode, mdp.compte_en_caisse, SUM(paiements.montant) as montant, SUM(paiements.pourboire) as pourboire')
            ->get()
            ->map(fn ($ligne): array => [
                'mode' => (string) $ligne->mode,
                'compte_en_caisse' => (bool) $ligne->compte_en_caisse,
                'montant' => round((float) $ligne->montant, 2),
                'pourboire' => round((float) $ligne->pourboire, 2),
            ])
            ->all();
    }

    /**
     * Les gestes qui déplacent de l'argent sans vendre. Ce sont eux qu'on relit
     * quand un écart interroge : une remise, une annulation ou une note
     * réimprimée sont les trois façons connues de faire sortir de la valeur
     * sans que la caisse le voie.
     *
     * @return array{remises: array{nombre: int, montant: float}, annulations: int, reimpressions: int}
     */
    private function gestesSensibles(SessionCaisse $session): array
    {
        $notes = CommandeClient::query()
            ->where('session_caisse_id', $session->id)
            ->select('id');

        $remises = Remise::query()->whereIn('commande_client_id', $notes->clone())->get();

        return [
            'remises' => [
                'nombre' => $remises->count(),
                // Une remise en pourcentage n'a de montant que rapportée à sa
                // note : on le calcule note par note plutôt que d'additionner
                // des pourcentages, ce qui ne voudrait rien dire.
                'montant' => round($remises->sum(
                    fn (Remise $remise): float => $remise->montantPour(
                        $remise->commande?->montantLignesActives() ?? 0.0
                    )
                ), 2),
            ],
            'annulations' => Annulation::query()
                ->where('annulable_type', (new CommandeClient)->getMorphClass())
                ->whereIn('annulable_id', $notes->clone())
                ->count(),
            'reimpressions' => Impression::query()
                ->where('imprimable_type', (new CommandeClient)->getMorphClass())
                ->whereIn('imprimable_id', $notes->clone())
                ->count(),
        ];
    }
}
