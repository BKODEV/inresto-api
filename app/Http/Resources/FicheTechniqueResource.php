<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FicheTechniqueResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'produit_id' => $this->produit_id,
            'version' => $this->version,
            'statut' => $this->statut->value,
            // Coût de référence (meilleur prix catalogue), recalculé, jamais saisi.
            'cout_matiere' => $this->cout_matiere,
            'updated_by' => $this->updated_by,
            'produit' => new ProduitResource($this->whenLoaded('produit')),
            'lignes_matieres' => LigneFicheTechniqueResource::collection($this->whenLoaded('lignesMatiere')),
            'lignes_sous_recettes' => LigneFicheTechniqueSousRecetteResource::collection($this->whenLoaded('lignesSousRecette')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
