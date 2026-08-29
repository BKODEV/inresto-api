<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\CommandeClient;
use App\Models\LigneCommandeClient;
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
            'auteur' => $this->whenLoaded(
                'user',
                fn (): string => trim($this->user->prenom.' '.$this->user->nom),
            ),
            // La note concernée, qu'on ait annulé la commande entière ou l'une
            // de ses lignes : c'est par elle qu'un contrôle remonte au contexte.
            'commande_client_id' => $this->whenLoaded(
                'annulable',
                fn (): ?int => match (true) {
                    $this->annulable instanceof CommandeClient => $this->annulable->id,
                    $this->annulable instanceof LigneCommandeClient => $this->annulable->commande_client_id,
                    default => null,
                },
            ),
        ];
    }
}
