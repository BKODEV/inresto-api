<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SousRecetteResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'libelle' => $this->libelle,
            'quantite_produite' => $this->quantite_produite,
            'unite_id' => $this->unite_id,
            // Indicateur recalculé à partir du PMP moyen multi-site, jamais saisi.
            'cout_matiere' => $this->cout_matiere,
            'unite' => new UniteDeMesureResource($this->whenLoaded('unite')),
            'lignes' => LigneSousRecetteResource::collection($this->whenLoaded('lignes')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
