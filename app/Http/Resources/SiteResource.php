<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SiteResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nom' => $this->nom,
            'code' => $this->code,
            'adresse' => $this->adresse,
            'statut' => $this->statut->value,
            // Points de vente rattachés (détail de site) + leur nombre.
            'points_de_vente' => PointDeVenteResource::collection($this->whenLoaded('pointsDeVente')),
            'points_de_vente_count' => $this->whenCounted('pointsDeVente'),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
