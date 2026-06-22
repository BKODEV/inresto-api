<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PointDeVenteResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'site_id' => $this->site_id,
            'nom' => $this->nom,
            'type' => $this->type->value,
            'description' => $this->description,
            'statut' => $this->statut->value,
            'caisses_count' => $this->whenCounted('caisses'),
            'caisses' => CaisseResource::collection($this->whenLoaded('caisses')),
            'site' => new SiteResource($this->whenLoaded('site')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
