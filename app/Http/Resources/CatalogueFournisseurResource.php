<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CatalogueFournisseurResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'fournisseur_id' => $this->fournisseur_id,
            'matiere_premiere_id' => $this->matiere_premiere_id,
            'prix_unitaire' => $this->prix_unitaire,
            'unite_achat_id' => $this->unite_achat_id,
            'qte_par_unite_achat' => $this->qte_par_unite_achat,
            'fournisseur' => new FournisseurResource($this->whenLoaded('fournisseur')),
            'matiere_premiere' => new MatierePremiereResource($this->whenLoaded('matierePremiere')),
            'unite_achat' => new UniteDeMesureResource($this->whenLoaded('uniteAchat')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
