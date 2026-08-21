<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\StatutSessionCaisse;
use App\Exceptions\VersementException;
use App\Models\Caisse;
use App\Models\SessionCaisse;
use App\Models\Versement;
use Illuminate\Support\Facades\DB;

/**
 * Sorties d'argent du tiroir : versement au coffre, à la banque, à la direction.
 *
 * Un versement se rattache TOUJOURS à une session — celle qui est ouverte, ou
 * la dernière fermée quand la caisse dort entre deux services. C'est ce
 * rattachement qui permet de dire, plus tard, si l'argent est sorti pendant le
 * service (il se retranche du théorique) ou après (il explique l'écart
 * d'ouverture suivant).
 */
class VersementService
{
    /**
     * @param  array{montant: float|string, destination: string, beneficiaire_id?: string|null, remis_a?: string|null, reference?: string|null, notes?: string|null}  $data
     *
     * @throws VersementException
     */
    public function enregistrer(Caisse $caisse, array $data, string $userId): Versement
    {
        return DB::transaction(function () use ($caisse, $data, $userId): Versement {
            $session = $this->sessionDeRattachement($caisse);

            $versement = new Versement([
                'caisse_id' => $caisse->id,
                'session_caisse_id' => $session->id,
                // Le service sait sans ambiguïté si la caisse tournait ; le
                // redéduire d'une comparaison d'horodatages à la seconde ferait
                // basculer du mauvais côté un versement fait dans la foulée
                // d'une clôture.
                'apres_fermeture' => ! $session->estOuverte(),
                'montant' => $data['montant'],
                'destination' => $data['destination'],
                'beneficiaire_id' => $data['beneficiaire_id'] ?? null,
                'remis_a' => $data['remis_a'] ?? null,
                'reference' => $data['reference'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);
            $versement->user_id = $userId;
            $versement->date_heure = now();
            $versement->save();

            return $versement->load(['caisse', 'user', 'beneficiaire']);
        });
    }

    /**
     * La session ouverte, ou la dernière fermée à défaut.
     *
     * Une caisse qui n'a jamais été ouverte n'a pas de tiroir d'où sortir de
     * l'argent : le versement n'aurait rien à expliquer et fausserait la
     * première transition venue.
     *
     * @throws VersementException
     */
    private function sessionDeRattachement(Caisse $caisse): SessionCaisse
    {
        $session = SessionCaisse::query()
            ->where('caisse_id', $caisse->id)
            ->orderByRaw('CASE WHEN statut = ? THEN 0 ELSE 1 END', [StatutSessionCaisse::Ouverte->value])
            ->orderByDesc('id')
            ->first();

        if ($session === null) {
            throw VersementException::caisseJamaisOuverte($caisse->id);
        }

        return $session;
    }
}
