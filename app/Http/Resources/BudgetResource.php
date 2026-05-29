<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BudgetResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'point_de_vente_id' => $this->point_de_vente_id,
            'mois' => $this->mois?->toDateString(),
            'montant_alloue' => $this->montant_alloue,
            'seuil_alerte_75' => $this->seuil_alerte_75,
            'seuil_alerte_90' => $this->seuil_alerte_90,
            'seuil_alerte_100' => $this->seuil_alerte_100,
            'point_de_vente' => new PointDeVenteResource($this->whenLoaded('pointDeVente')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
