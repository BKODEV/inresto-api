<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VersementResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'caisse_id' => $this->caisse_id,
            'session_caisse_id' => $this->session_caisse_id,
            'montant' => $this->montant,
            'destination' => $this->destination->value,
            'destination_libelle' => $this->destination->libelle(),
            'remis_a' => $this->remis_a,
            'reference' => $this->reference,
            'notes' => $this->notes,
            'date_heure' => $this->date_heure?->toIso8601String(),
            // Sorti pendant le service (retranché du théorique) ou après la
            // fermeture (il explique l'écart d'ouverture suivant).
            'en_service' => $this->whenLoaded('session', fn (): bool => $this->estEnService()),
            'auteur' => $this->whenLoaded(
                'user',
                fn (): string => trim($this->user->prenom.' '.$this->user->nom),
            ),
        ];
    }
}
