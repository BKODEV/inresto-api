<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SessionCaisseResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'caisse_id' => $this->caisse_id,
            'user_id' => $this->user_id,
            'ouvert_le' => $this->ouvert_le?->toIso8601String(),
            'fonds_initial' => $this->fonds_initial,
            'ferme_le' => $this->ferme_le?->toIso8601String(),
            'montant_theorique' => $this->montant_theorique,
            'montant_reel' => $this->montant_reel,
            'ecart' => $this->ecart,
            'statut' => $this->statut->value,
            'notes' => $this->notes,
            'caisse' => new CaisseResource($this->whenLoaded('caisse')),
            'user' => $this->whenLoaded('user', fn () => [
                'id' => $this->user->id,
                'nom' => $this->user->nom,
                'prenom' => $this->user->prenom,
            ]),
        ];
    }
}
