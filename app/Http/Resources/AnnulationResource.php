<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AnnulationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'annulable_type' => $this->annulable_type,
            'annulable_id' => $this->annulable_id,
            'sort' => $this->sort,
            'motif' => $this->motif,
            'date_heure' => $this->date_heure?->toIso8601String(),
        ];
    }
}
