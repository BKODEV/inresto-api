<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MouvementStockResource extends JsonResource
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
            'user_id' => $this->user_id,
            'type' => $this->type->value,
            // 'entree' | 'sortie' | 'neutre' : `quantite` est toujours positive,
            // c'est le sens qui porte l'impact sur le stock.
            'sens' => $this->type->sens(),
            'quantite' => $this->quantite,
            // PMP figé à l'instant du mouvement, jamais le PMP courant.
            'pmp_au_moment' => $this->pmp_au_moment,
            'valeur' => $this->valeur(),
            'date_mouvement' => $this->date_mouvement?->toIso8601String(),
            // Origine : alias morphologique stable (cf. Relation::morphMap).
            'reference_type' => $this->reference_type,
            'reference_id' => $this->reference_id,
            'note' => $this->note,
            'matiere_premiere' => new MatierePremiereResource($this->whenLoaded('matiere')),
            'site' => new SiteResource($this->whenLoaded('site')),
            // Auteur réduit à son nom : un journal n'a pas besoin de la fiche
            // utilisateur complète (photo, email, rôles...). Null sur les
            // mouvements automatiques.
            'auteur' => $this->whenLoaded(
                'user',
                fn (): ?string => $this->user === null
                    ? null
                    : trim($this->user->prenom.' '.$this->user->nom),
            ),
        ];
    }
}
