<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProduitResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'categorie_id' => $this->categorie_id,
            'libelle' => $this->libelle,
            'description' => $this->description,
            'est_menu' => $this->est_menu,
            'photo' => $this->photo,
            'allergenes' => $this->allergenes,
            'statut' => $this->statut->value,
            'categorie' => new CategorieProduitResource($this->whenLoaded('categorie')),
            // Fiche technique active (avec lignes) quand la relation est chargée
            // — null si le produit n'en a pas encore. Voir ProduitController@show.
            'fiche_technique' => $this->when(
                $this->relationLoaded('fichesTechniques'),
                fn () => $this->fichesTechniques->isNotEmpty()
                    ? new FicheTechniqueResource($this->fichesTechniques->first())
                    : null
            ),
        ];
    }
}
