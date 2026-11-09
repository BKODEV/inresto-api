<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\LigneBonCuisine;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Un bon de cuisine, tel qu'il sera lu au poste : quoi, pour quelle table, de
 * la part de qui. Aucun prix — la cuisine n'en a pas l'usage.
 */
class BonCuisineResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $nom = fn (?User $user): ?string => $user === null ? null : trim($user->prenom.' '.$user->nom);

        return [
            'id' => $this->id,
            'numero' => $this->numero,
            'jour' => $this->jour?->toDateString(),
            'type' => $this->type->value,
            'type_libelle' => $this->type->libelle(),
            'commande_id' => $this->commande_client_id,
            'point_de_vente_id' => $this->point_de_vente_id,
            'poste_cuisine_id' => $this->poste_cuisine_id,
            'poste_libelle' => $this->poste_libelle,
            'partie' => $this->partie,
            'parties' => $this->parties,
            'emis_le' => $this->emis_le?->toIso8601String(),
            'imprime_le' => $this->imprime_le?->toIso8601String(),
            'nombre_impressions' => $this->nombre_impressions,
            'a_imprimer' => $this->estAImprimer(),
            // Devenu inutile avant d'être imprimé : servi ou annulé sans lui.
            'sans_objet' => $this->estSansObjet(),
            'sans_objet_le' => $this->sans_objet_le?->toIso8601String(),
            // Figé sur la note à son ouverture : la table peut avoir été renumérotée.
            'table_numero' => $this->whenLoaded('commande', fn (): ?string => $this->commande->table_numero),
            'serveur' => $this->whenLoaded('commande', fn (): ?string => $nom($this->commande->user)),
            'emis_par' => $this->whenLoaded('user', fn (): ?string => $nom($this->user)),
            'lignes' => $this->whenLoaded(
                'lignes',
                fn (): array => $this->lignes
                    ->map(fn (LigneBonCuisine $ligne): array => [
                        'id' => $ligne->id,
                        'ligne_commande_client_id' => $ligne->ligne_commande_client_id,
                        'produit_libelle' => $ligne->produit_libelle,
                        'quantite' => $ligne->quantite,
                        'commentaire' => $ligne->commentaire,
                        'sans_objet' => $ligne->sans_objet,
                    ])
                    ->all(),
            ),
        ];
    }
}
