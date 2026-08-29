<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RemiseResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'commande_client_id' => $this->commande_client_id,
            'user_id' => $this->user_id,
            'type' => $this->type->value,
            'valeur' => $this->valeur,
            'motif' => $this->motif,
            'date_heure' => $this->date_heure?->toIso8601String(),
            // Nom de qui a accordé la remise, composé ici comme pour `serveur` :
            // un écran de contrôle cherche une personne, pas un identifiant.
            'auteur' => $this->whenLoaded(
                'user',
                fn (): string => trim($this->user->prenom.' '.$this->user->nom),
            ),
            // Numéro figé de la table, pour situer la note sans second appel.
            'table_numero' => $this->whenLoaded(
                'commande',
                fn (): ?string => $this->commande->table_numero,
            ),
        ];
    }
}
