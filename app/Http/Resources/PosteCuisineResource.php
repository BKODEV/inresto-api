<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PosteCuisineResource extends JsonResource
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
            'ordre' => $this->ordre,
            'par_defaut' => $this->par_defaut,
            'statut' => $this->statut->value,
            // Les catégories rattachées : c'est ce que l'écran de paramétrage
            // coche, et ce qui filtre ensuite le KDS.
            'categories' => CategorieProduitResource::collection($this->whenLoaded('categories')),
            // Qui tient le poste. Composé ici comme `brigade` sur un service :
            // l'écran a besoin d'un nom, pas d'une fiche de personnel.
            'membres' => $this->whenLoaded(
                'membres',
                fn (): array => $this->membres
                    ->map(fn (User $u): array => [
                        'id' => $u->id,
                        'nom' => trim($u->prenom.' '.$u->nom),
                    ])
                    ->all(),
            ),
            'point_de_vente' => new PointDeVenteResource($this->whenLoaded('pointDeVente')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
