<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StockResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'matiere_premiere_id' => $this->matiere_premiere_id,
            'site_id' => $this->site_id,
            // Alimentés uniquement par le StockService, jamais saisis.
            'stock_courant' => $this->stock_courant,
            'pmp' => $this->pmp,
            'valeur' => $this->valeur(),
            'seuil_alerte_stock' => $this->matiere?->seuil_alerte_stock,
            'sous_seuil' => $this->estSousSeuil(),
            'matiere_premiere' => new MatierePremiereResource($this->whenLoaded('matiere')),
            'site' => new SiteResource($this->whenLoaded('site')),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
