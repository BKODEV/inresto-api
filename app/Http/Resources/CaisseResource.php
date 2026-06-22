<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CaisseResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'point_de_vente_id' => $this->point_de_vente_id,
            'libelle' => $this->libelle,
            'statut' => $this->statut->value,
            // Nombre de sessions : >0 ⇒ la caisse a un historique et ne peut être supprimée.
            'sessions_count' => $this->whenCounted('sessions'),
            'point_de_vente' => new PointDeVenteResource($this->whenLoaded('pointDeVente')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
