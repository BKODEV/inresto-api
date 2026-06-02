<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LigneInventaireResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'inventaire_id' => $this->inventaire_id,
            'matiere_premiere_id' => $this->matiere_premiere_id,
            'quantite_theorique' => $this->quantite_theorique,
            'quantite_physique' => $this->quantite_physique,
            // null tant que l'inventaire est en cours (figé à la clôture).
            'ecart' => $this->ecart,
            'matiere_premiere' => new MatierePremiereResource($this->whenLoaded('matierePremiere')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
