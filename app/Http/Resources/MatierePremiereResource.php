<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MatierePremiereResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'categorie_id' => $this->categorie_id,
            'unite_de_mesure_id' => $this->unite_de_mesure_id,
            'libelle' => $this->libelle,
            'seuil_alerte_stock' => $this->seuil_alerte_stock,
            'gestion_peremption' => $this->gestion_peremption,
            // Le stock (stock_courant/pmp) est par site, exposé via la table `stocks`.
            'statut' => $this->statut->value,
            'categorie' => new CategorieMatierePremireResource($this->whenLoaded('categorie')),
            'unite' => new UniteDeMesureResource($this->whenLoaded('unite')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
