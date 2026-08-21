<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\StatutSessionCaisse;
use App\Exceptions\SessionCaisseException;
use App\Models\SessionCaisse;
use Illuminate\Support\Facades\DB;

/**
 * Cycle de vie d'une session de caisse : ouverture (une seule ouverte par
 * caisse) et fermeture (rapprochement réel/théorique, calcul de l'écart).
 *
 * `montant_theorique` agrège `fonds_initial + Σ paiements de la session`, via
 * {@see SessionCaisse::montantPaye()}. Le rapprochement est donc réel : un
 * paiement enregistré compte immédiatement comme de l'argent en tiroir, ce qui
 * suppose que le caissier saisit au moment où l'argent lui est remis.
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

            return $session->load(['caisse.pointDeVente.site', 'user']);
        });
    }

    /**
     * Ferme une session ouverte : fige montant_theorique, montant_reel, ecart et
     * ferme_le. Seule une session ouverte peut être fermée (409 sinon).
     *
     * @param  array{montant_reel: float|string, notes?: string|null}  $data
     *
     * @throws SessionCaisseException
     */
    public function fermer(SessionCaisse $session, array $data): SessionCaisse
    {
        if (! $session->estOuverte()) {
            throw SessionCaisseException::dejaFermee($session->id);
        }

        return DB::transaction(function () use ($session, $data): SessionCaisse {
            $montantTheorique = (float) $session->fonds_initial + $session->montantPaye();
            $montantReel = (float) $data['montant_reel'];

            $session->montant_theorique = $montantTheorique;
            $session->montant_reel = $montantReel;
            $session->ecart = $montantReel - $montantTheorique;
            $session->ferme_le = now();
            $session->statut = StatutSessionCaisse::Fermee;

            if (array_key_exists('notes', $data)) {
                $session->notes = $data['notes'];
            }

            $session->save();

            return $session->load(['caisse.pointDeVente.site', 'user']);
        });
    }
}
