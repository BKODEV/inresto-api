<?php

declare(strict_types=1);

namespace App\Http\Requests\CatalogueFournisseur;

use App\Models\CatalogueFournisseur;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class SyncCatalogueFournisseurRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', CatalogueFournisseur::class);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'lignes' => ['present', 'array'],
            'lignes.*.matiere_premiere_id' => ['required', 'integer', 'exists:matieres_premieres,id', 'distinct'],
            'lignes.*.prix_unitaire' => ['required', 'numeric', 'min:0'],
            'lignes.*.unite_achat_id' => ['required', 'integer', 'exists:unites_de_mesure,id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'lignes.*.matiere_premiere_id.distinct' => 'Une même matière première ne peut figurer deux fois dans le catalogue.',
        ];
    }
}
