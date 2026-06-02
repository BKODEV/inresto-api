<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LigneFicheTechniqueSousRecetteResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'fiche_technique_id' => $this->fiche_technique_id,
            'sous_recette_id' => $this->sous_recette_id,
            'quantite' => $this->quantite,
            'sous_recette' => new SousRecetteResource($this->whenLoaded('sousRecette')),
        ];
    }
}
