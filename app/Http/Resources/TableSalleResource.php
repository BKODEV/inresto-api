<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TableSalleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'point_de_vente_id' => $this->point_de_vente_id,
            'numero' => $this->numero,
            'capacite' => $this->capacite,
            'statut' => $this->statut->value,
            // Jeton + lien public du QR : destinés à l'ADMIN (affichage/impression
            // du QR sur le plan de tables), pas au client final.
            'public_token' => $this->public_token,
            'menu_url' => $this->menuUrl(),
            'point_de_vente' => new PointDeVenteResource($this->whenLoaded('pointDeVente')),
        ];
    }
}
