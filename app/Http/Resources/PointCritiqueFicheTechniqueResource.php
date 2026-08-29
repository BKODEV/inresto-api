<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PointCritiqueFicheTechniqueResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'ordre' => $this->ordre,
            'type' => $this->type->value,
            'type_libelle' => $this->type->libelle(),
            'description' => $this->description,
        ];
    }
}
