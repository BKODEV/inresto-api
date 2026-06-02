<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LigneFicheTechniqueResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'fiche_technique_id' => $this->fiche_technique_id,
            'matiere_premiere_id' => $this->matiere_premiere_id,
            'unite_id' => $this->unite_id,
            'quantite' => $this->quantite,
            'matiere_premiere' => new MatierePremiereResource($this->whenLoaded('matierePremiere')),
            'unite' => new UniteDeMesureResource($this->whenLoaded('unite')),
        ];
    }
}
