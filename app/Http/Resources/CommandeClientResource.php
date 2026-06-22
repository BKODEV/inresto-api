<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CommandeClientResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'table_id' => $this->table_id,
            'session_caisse_id' => $this->session_caisse_id,
            'user_id' => $this->user_id,
            'reservation_id' => $this->reservation_id,
            'appartement_id' => $this->appartement_id,
            'appartement_nom' => $this->appartement_nom,
            'appartement_a_verifier' => $this->appartement_a_verifier,
            'date_heure' => $this->date_heure?->toIso8601String(),
            'statut' => $this->statut->value,
            'montant_total' => $this->montant_total,
            // Valeurs calculées à la volée (non stockées).
            'montant_du' => number_format($this->montantDu(), 2, '.', ''),
            'montant_paye' => number_format($this->montantPaye(), 2, '.', ''),
            'reste_a_payer' => number_format($this->resteAPayer(), 2, '.', ''),
            'notes' => $this->notes,
            'reference_facture_normalisee' => $this->reference_facture_normalisee,
            'table' => new TableSalleResource($this->whenLoaded('table')),
            'session_caisse' => new SessionCaisseResource($this->whenLoaded('sessionCaisse')),
            'lignes' => LigneCommandeClientResource::collection($this->whenLoaded('lignes')),
            'paiements' => PaiementResource::collection($this->whenLoaded('paiements')),
            'remises' => RemiseResource::collection($this->whenLoaded('remises')),
        ];
    }
}
