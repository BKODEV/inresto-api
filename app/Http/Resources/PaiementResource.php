<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PaiementResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'commande_client_id' => $this->commande_client_id,
            'mode_de_paiement_id' => $this->mode_de_paiement_id,
            'user_id' => $this->user_id,
            'montant' => $this->montant,
            'pourboire' => $this->pourboire,
            'montant_recu' => $this->montant_recu,
            'date_heure' => $this->date_heure?->toIso8601String(),
            'reference_externe' => $this->reference_externe,
            'mode_de_paiement' => new ModeDePaiementResource($this->whenLoaded('modePaiement')),
        ];
    }
}
