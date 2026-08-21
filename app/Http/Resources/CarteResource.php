<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CarteResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'libelle' => $this->libelle,
            // Exposé à plat en plus de l'objet imbriqué : les écrans qui
            // rattachent une carte à son point de vente n'ont pas toujours la
            // relation chargée, et `whenLoaded` la ferait disparaître.
            'point_de_vente_id' => $this->point_de_vente_id,
            'statut' => $this->statut->value,
            'point_de_vente' => new PointDeVenteResource($this->whenLoaded('pointDeVente')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'deleted_at' => $this->deleted_at?->toIso8601String(),
        ];
    }
}
