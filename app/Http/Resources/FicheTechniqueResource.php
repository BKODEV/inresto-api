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
            // Méthodologie : sans elle, un intérimaire tient la liste des
            // matières sans savoir quoi en faire.
            'etapes' => EtapeFicheTechniqueResource::collection($this->whenLoaded('etapes')),
            'materiels' => MaterielFicheTechniqueResource::collection($this->whenLoaded('materiels')),
            'points_critiques' => PointCritiqueFicheTechniqueResource::collection($this->whenLoaded('pointsCritiques')),
            // Somme des étapes chiffrées ; null si aucune ne l'est, plutôt qu'un
            // « 0 min » qui se lirait comme une recette instantanée.
            'duree_totale_minutes' => $this->whenLoaded(
                'etapes',
                fn (): ?int => $this->dureeTotaleMinutes(),
            ),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
