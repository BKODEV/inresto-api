<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SessionCaisseResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'caisse_id' => $this->caisse_id,
            'user_id' => $this->user_id,
            'ouvert_le' => $this->ouvert_le?->toIso8601String(),
            'fonds_initial' => $this->fonds_initial,
            'ferme_le' => $this->ferme_le?->toIso8601String(),
            'montant_theorique' => $this->montant_theorique,
            'montant_reel' => $this->montant_reel,
            'ecart' => $this->ecart,
            // Le sens se calcule ici et non côté client : il dépend de la
            // tolérance du site, que le front n'a pas sous la main.
            'ecart_sens' => $this->when(
                $this->ferme_le !== null,
                fn (): string => $this->sensEcart($this->toleranceDuSite()),
            ),
            // Encaissé sur la session sans passer par le tiroir. Sans ce
            // chiffre, une caisse vide après 300 000 de virements donne un écart
            // nul incompréhensible.
            //
            // Réservé aux sessions fermées : c'est un chiffre de rapprochement,
            // et le calculer sur chaque ligne de l'index coûterait une requête
            // par session ouverte pour un nombre que personne ne lit.
            'montant_hors_caisse' => $this->when(
                $this->ferme_le !== null,
                fn (): string => number_format($this->montantHorsCaisse(), 2, '.', ''),
            ),
            'statut' => $this->statut->value,
            'ferme_par' => $this->whenLoaded(
                'fermePar',
                fn (): ?string => trim($this->fermePar->prenom.' '.$this->fermePar->nom),
            ),
            'notes' => $this->notes,
            // Un contenant par mode : le tiroir, le terminal, la pochette de
            // chèques. C'est la fermeture détaillée, dont `ecart` n'est que la
            // part tiroir.
            'rapprochements' => RapprochementCaisseResource::collection(
                $this->whenLoaded('rapprochements'),
            ),
            'caisse' => new CaisseResource($this->whenLoaded('caisse')),
            'user' => $this->whenLoaded('user', fn () => [
                'id' => $this->user->id,
                'nom' => $this->user->nom,
                'prenom' => $this->user->prenom,
            ]),
        ];
    }

    /**
     * Tolérance de l'établissement dont dépend cette caisse. Nulle si la chaîne
     * caisse → point de vente → site → paramètres n'est pas chargée : la
     * resource ne déclenche pas de requête, c'est au contrôleur d'eager-loader.
     */
    private function toleranceDuSite(): float
    {
        $parametre = $this->caisse?->pointDeVente?->site?->parametreEtablissement;

        return (float) ($parametre?->tolerance_ecart_caisse ?? 0);
    }
}
