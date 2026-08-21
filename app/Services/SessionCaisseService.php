<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\StatutCommandeClient;
use App\Enums\StatutSessionCaisse;
use App\Exceptions\SessionCaisseException;
use App\Models\SessionCaisse;
use Illuminate\Support\Facades\DB;

/**
 * Cycle de vie d'une session de caisse : ouverture (une seule ouverte par
 * caisse) et fermeture (rapprochement réel/théorique, calcul de l'écart).
 *
 * `montant_theorique` agrège `fonds_initial + Σ paiements EN TIROIR de la
 * session`, via {@see SessionCaisse::montantEnCaisse()}. Seuls les modes marqués
 * `compte_en_caisse` y entrent : `montant_reel` est ce que le caissier a
 * physiquement compté, et un virement n'y figure pas.
 *
 * Le rapprochement suppose toujours que la saisie a lieu au moment où l'argent
 * est remis — un paiement enregistré compte immédiatement comme en tiroir.
 *
 * POINT D'EXTENSION :
 *  - Le périmètre d'accès « utiliser une session » se remonte via
 *    caisse → point de vente → site, croisé avec le pivot `user_site` et la
 *    permission `session_caisse.utiliser`. Contrôle appliqué au Lot C (création
 *    de commandes sur une session ouverte) ; non requis pour ouvrir/fermer.
 */
class SessionCaisseService
{
    /**
     * Ouvre une session sur une caisse. Refuse (409) si une session ouverte
     * existe déjà pour cette caisse. La vérification est verrouillée
     * (lockForUpdate) et doublée d'un index unique partiel en base.
     *
     * @param  array{caisse_id: int, fonds_initial?: float|string|null}  $data
     *
     * @throws SessionCaisseException
     */
    public function ouvrir(array $data, string $userId): SessionCaisse
    {
        $caisseId = (int) $data['caisse_id'];

        return DB::transaction(function () use ($caisseId, $data, $userId): SessionCaisse {
            $dejaOuverte = SessionCaisse::query()
                ->where('caisse_id', $caisseId)
                ->where('statut', StatutSessionCaisse::Ouverte->value)
                ->lockForUpdate()
                ->exists();

            if ($dejaOuverte) {
                throw SessionCaisseException::dejaOuvertePourLaCaisse($caisseId);
            }

            $session = new SessionCaisse([
                'caisse_id' => $caisseId,
                'fonds_initial' => $data['fonds_initial'] ?? 0,
            ]);
            $session->user_id = $userId;
            $session->statut = StatutSessionCaisse::Ouverte;
            $session->ouvert_le = now();
            $session->save();

            $this->reporterNotesEnCours($caisseId, $session);

            return $session->load(['caisse.pointDeVente.site', 'user']);
        });
    }

    /**
     * Fige un rapprochement par mode de paiement actif, et renvoie le total
     * constaté dans le TIROIR — ou null si personne n'y a rien compté.
     *
     * Le théorique de chaque contenant est gelé ici : un rapprochement est une
     * pièce d'audit, il ne se recalcule pas après coup.
     *
     * @param  array<int, array{mode_de_paiement_id: int, montant_constate?: float|string|null}>  $declares
     */
    private function figerRapprochements(SessionCaisse $session, array $declares): ?float
    {
        $parMode = collect($declares)->keyBy('mode_de_paiement_id');

        $tiroir = null;

        foreach ($session->theoriqueParMode() as $ligne) {
            $mode = $ligne['mode'];
            $declare = $parMode->get($mode->id)['montant_constate'] ?? null;
            $constate = $declare === null ? null : round((float) $declare, 2);

            $session->rapprochements()->create([
                'mode_de_paiement_id' => $mode->id,
                'montant_theorique' => $ligne['theorique'],
                'montant_constate' => $constate,
                'ecart' => $constate === null ? null : round($constate - $ligne['theorique'], 2),
            ]);

            if ($mode->compte_en_caisse && $constate !== null) {
                $tiroir = round(($tiroir ?? 0.0) + $constate, 2);
            }
        }

        return $tiroir;
    }

    /**
     * Reprend les notes encore en service laissées par la session précédente.
     *
     * Une table qui mange encore à l'heure de la relève ne doit ni bloquer la
     * fermeture ni rester attachée à une caisse close : son règlement viendra
     * dans la session qui suit, et c'est là qu'il doit compter.
     *
     * Le report a lieu à l'OUVERTURE et non à la fermeture, faute de session
     * d'accueil à ce moment-là.
     *
     * Aucun argent ne change de tiroir au passage : un paiement n'est accepté
     * que sur une note SERVIE ({@see PaiementService::ajouter()}), donc une note
     * encore en cours n'en porte aucun.
     */
    private function reporterNotesEnCours(int $caisseId, SessionCaisse $nouvelle): void
    {
        $precedente = SessionCaisse::query()
            ->where('caisse_id', $caisseId)
            ->whereKeyNot($nouvelle->id)
            ->orderByDesc('id')
            ->first();

        if ($precedente === null) {
            return;
        }

        $precedente->commandes()
            ->whereIn('statut', [
                StatutCommandeClient::Ouverte->value,
                StatutCommandeClient::EnvoyeeEnCuisine->value,
            ])
            ->update(['session_caisse_id' => $nouvelle->id]);
    }

    /**
     * Ferme une session ouverte : fige montant_theorique, montant_reel, ecart et
     * ferme_le. Seule une session ouverte peut être fermée (409 sinon).
     *
     * @param  array{rapprochements?: array<int, array{mode_de_paiement_id: int, montant_constate?: float|string|null}>, notes?: string|null}  $data
     * @param  string|null  $userId  Signataire de la fermeture.
     *
     * @throws SessionCaisseException
     */
    public function fermer(SessionCaisse $session, array $data, ?string $userId = null): SessionCaisse
    {
        if (! $session->estOuverte()) {
            throw SessionCaisseException::dejaFermee($session->id);
        }

        // Une note servie non réglée est un impayé : le client a consommé et
        // n'a pas payé. Fermer par-dessus la rendrait orpheline — son argent ne
        // compterait dans le théorique d'aucune caisse, et l'impayé
        // disparaîtrait sans trace. Les notes encore en cours de service, elles,
        // ne bloquent pas : voir ouvrir(), qui les reporte.
        $impayees = $session->notesServiesNonReglees();

        if ($impayees->isNotEmpty()) {
            throw SessionCaisseException::notesNonReglees($session->id, $impayees->count());
        }

        return DB::transaction(function () use ($session, $data, $userId): SessionCaisse {
            $tiroir = $this->figerRapprochements($session, $data['rapprochements'] ?? []);

            $session->montant_theorique = (float) $session->fonds_initial + $session->montantEnCaisse();
            // Null et non zéro quand rien n'a été constaté dans le tiroir :
            // annoncer un manquant égal à la recette du service parce que
            // personne n'a compté serait pire que de ne rien annoncer.
            $session->montant_reel = $tiroir;
            $session->ecart = $tiroir === null ? null : $tiroir - $session->montant_theorique;
            $session->ferme_le = now();
            // Le geste de fermeture fige l'écart : le signer complète la piste
            // d'audit, `user_id` ne désignant que l'ouverture.
            $session->ferme_par = $userId;
            $session->statut = StatutSessionCaisse::Fermee;

            if (array_key_exists('notes', $data)) {
                $session->notes = $data['notes'];
            }

            $session->save();

            // La fiche d'établissement porte la tolérance d'écart : sans elle, le
            // `ecart_sens` renvoyé juste après la fermeture serait calculé avec
            // une tolérance de 0, et annoncerait « manquant » là où le site
            // tolère l'écart. Même chaîne que SessionCaisseController::RELATIONS.
            return $session->load([
                'caisse.pointDeVente.site.parametreEtablissement',
                'user',
                'fermePar',
                'rapprochements.modePaiement',
            ]);
        });
    }
}
