<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CarteProduitResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'carte_id' => $this->carte_id,
            'produit_id' => $this->produit_id,
            'prix_vente' => $this->prix_vente,
            'statut' => $this->statut->value,
            'produit' => new ProduitResource($this->whenLoaded('produit')),
        ];
    }
}
