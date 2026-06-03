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
            // Présent uniquement quand le site est lu via la relation user_site.
            'est_principal' => $this->whenPivotLoaded('user_site', fn () => (bool) $this->pivot->est_principal),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
