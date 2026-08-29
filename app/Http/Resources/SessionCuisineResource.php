<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SessionCuisineResource extends JsonResource
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
            'statut' => $this->statut->value,
            'ouvert_le' => $this->ouvert_le?->toIso8601String(),
            'ferme_le' => $this->ferme_le?->toIso8601String(),
            'notes' => $this->notes,
            'point_de_vente' => new PointDeVenteResource($this->whenLoaded('pointDeVente')),
            // Qui a lancé et qui a clos. Les deux se distinguent : un service
            // traverse souvent une relève.
            'ouvert_par' => $this->whenLoaded(
                'ouvertPar',
                fn (): string => trim($this->ouvertPar->prenom.' '.$this->ouvertPar->nom),
            ),
            'ferme_par' => $this->whenLoaded(
                'fermePar',
                fn (): ?string => $this->fermePar === null
                    ? null
                    : trim($this->fermePar->prenom.' '.$this->fermePar->nom),
            ),
            // L'équipe : c'est elle qu'on veut lire en face de la production.
            'brigade' => $this->whenLoaded(
                'brigade',
                fn (): array => $this->brigade
                    ->map(fn (User $u): array => [
                        'id' => $u->id,
                        'nom' => trim($u->prenom.' '.$u->nom),
                    ])
                    ->all(),
            ),
            'lignes_count' => $this->whenCounted('lignes'),
        ];
    }
}
