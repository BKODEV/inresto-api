<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LigneCommandeClientResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'commande_client_id' => $this->commande_client_id,
            'produit_id' => $this->produit_id,
            'fiche_technique_id' => $this->fiche_technique_id,
            'quantite' => $this->quantite,
            'prix_unitaire' => $this->prix_unitaire,
            'commentaire' => $this->commentaire,
            'statut' => $this->statut->value,
            // Horodatage du tour : les lignes parties ensemble le partagent.
            'envoyee_le' => $this->envoyee_le?->toIso8601String(),
            'servie_le' => $this->servie_le?->toIso8601String(),
            // Jamais partie en cuisine : se retire sans motif d'annulation.
            'retirable_sans_motif' => $this->estRetirableSansMotif(),
            'produit' => new ProduitResource($this->whenLoaded('produit')),
        ];
    }
}
