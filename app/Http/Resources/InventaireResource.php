<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InventaireResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'site_id' => $this->site_id,
            'user_id' => $this->user_id,
            'date_inventaire' => $this->date_inventaire?->toDateString(),
            'statut' => $this->statut->value,
            // Figée à la clôture ; 0 tant que l'inventaire est en cours.
            'valeur_totale_stock' => $this->valeur_totale_stock,
            'notes' => $this->notes,
            'site' => new SiteResource($this->whenLoaded('site')),
            'lignes' => LigneInventaireResource::collection($this->whenLoaded('lignes')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
