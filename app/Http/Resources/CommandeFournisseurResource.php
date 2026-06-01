<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CommandeFournisseurResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'numero' => $this->numero,
            'fournisseur_id' => $this->fournisseur_id,
            'user_id' => $this->user_id,
            'date_commande' => $this->date_commande?->toDateString(),
            'statut' => $this->statut->value,
            // Recalculé automatiquement à partir des lignes, jamais saisi.
            'montant_previsionnel' => $this->montant_previsionnel,
            'notes' => $this->notes,
            'fournisseur' => new FournisseurResource($this->whenLoaded('fournisseur')),
            'lignes' => LigneCommandeFournisseurResource::collection($this->whenLoaded('lignes')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
