<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class ParametreEtablissementResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'site_id' => $this->site_id,
            'nom_commercial' => $this->nom_commercial,
            'slogan' => $this->slogan,
            'logo_path' => $this->logo_path,
            'logo_url' => $this->logo_path !== null
                ? Storage::disk('public')->url($this->logo_path)
                : null,
            'adresse' => $this->adresse,
            'telephone' => $this->telephone,
            'email' => $this->email,
            'site_web' => $this->site_web,
            'raison_sociale' => $this->raison_sociale,
            'ncc' => $this->ncc,
            'rccm' => $this->rccm,
            'regime_fiscal' => $this->regime_fiscal,
            'devise' => $this->devise,
            'tolerance_ecart_caisse' => $this->tolerance_ecart_caisse,
            'mentions_pied_page' => $this->mentions_pied_page,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
