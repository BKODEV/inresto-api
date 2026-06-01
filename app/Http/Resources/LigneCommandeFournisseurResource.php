<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LigneCommandeFournisseurResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'commande_fournisseur_id' => $this->commande_fournisseur_id,
            'matiere_premiere_id' => $this->matiere_premiere_id,
            'unite_id' => $this->unite_id,
            'quantite_commandee' => $this->quantite_commandee,
            'prix_unitaire_previsionnel' => $this->prix_unitaire_previsionnel,
            'sous_total' => $this->sousTotal(),
            'matiere_premiere' => new MatierePremiereResource($this->whenLoaded('matierePremiere')),
            'unite' => new UniteDeMesureResource($this->whenLoaded('unite')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
