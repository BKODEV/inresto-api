<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DepenseResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'budget_id' => $this->budget_id,
            'type' => $this->type,
            'libelle' => $this->libelle,
            'montant' => $this->montant,
            'date_depense' => $this->date_depense?->toDateString(),
            // null pour une charge manuelle ; renseigné pour une dépense issue d'une réception.
            'reception_id' => $this->reception_id,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
