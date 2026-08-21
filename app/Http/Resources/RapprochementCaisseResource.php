<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RapprochementCaisseResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'mode_de_paiement_id' => $this->mode_de_paiement_id,
            'mode' => $this->whenLoaded('modePaiement', fn (): string => $this->modePaiement->libelle),
            'compte_en_caisse' => $this->whenLoaded(
                'modePaiement',
                fn (): bool => $this->modePaiement->compte_en_caisse,
            ),
            'montant_theorique' => $this->montant_theorique,
            // Null = personne n'a déclaré ce contenant. Le front l'affiche
            // « non rapproché » plutôt que de laisser croire à un zéro constaté.
            'montant_constate' => $this->montant_constate,
            'ecart' => $this->ecart,
            'ecart_sens' => $this->sensEcart($this->toleranceDuSite()),
        ];
    }

    /**
     * Tolérance de l'établissement, remontée par la session. Nulle si la chaîne
     * n'est pas chargée : la resource ne déclenche aucune requête.
     */
    private function toleranceDuSite(): float
    {
        $parametre = $this->session?->caisse?->pointDeVente?->site?->parametreEtablissement;

        return (float) ($parametre?->tolerance_ecart_caisse ?? 0);
    }
}
