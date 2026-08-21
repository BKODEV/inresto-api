<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\PaiementException;
use App\Models\CommandeClient;
use App\Models\Paiement;

/**
 * Enregistre les paiements d'une commande. Plusieurs paiements (fractionnement)
 * sont possibles, le surpaiement est autorisé. Un paiement n'est accepté que sur
 * une commande SERVIE, jamais sur une commande encaissée (immuable).
 */
class PaiementService
{
    /**
     * @param  array{mode_de_paiement_id: int, montant: float|string, pourboire?: float|string|null, montant_recu?: float|string|null, reference_externe?: string|null}  $data
     *
     * @throws PaiementException
     */
    public function ajouter(CommandeClient $commande, array $data, string $userId): Paiement
    {
        if ($commande->estEncaissee()) {
            throw PaiementException::commandeImmuable($commande->id);
        }

        if (! $commande->estServie()) {
            throw PaiementException::commandeNonServie($commande->id);
        }

        $paiement = $commande->paiements()->make([
            'mode_de_paiement_id' => $data['mode_de_paiement_id'],
            'montant' => $data['montant'],
            'pourboire' => $data['pourboire'] ?? 0,
            'montant_recu' => $data['montant_recu'] ?? null,
            'reference_externe' => $data['reference_externe'] ?? null,
        ]);
        $paiement->user_id = $userId;
        $paiement->date_heure = now();
        $paiement->save();

        return $paiement->load('modePaiement');
    }
}
