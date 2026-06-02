<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReceptionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'commande_fournisseur_id' => $this->commande_fournisseur_id,
            'site_id' => $this->site_id,
            'user_id' => $this->user_id,
            'date_reception' => $this->date_reception?->toDateString(),
            // Recalculé à la confirmation (Σ quantité reçue × prix réel), jamais saisi.
            'montant_reel' => $this->montant_reel,
            'observations' => $this->observations,
            'commande' => new CommandeFournisseurResource($this->whenLoaded('commande')),
            'site' => new SiteResource($this->whenLoaded('site')),
            'lignes' => LigneReceptionResource::collection($this->whenLoaded('lignes')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
