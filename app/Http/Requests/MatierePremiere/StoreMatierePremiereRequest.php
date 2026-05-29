<?php

declare(strict_types=1);

namespace App\Http\Requests\MatierePremiere;

use App\Enums\Statut;
use App\Models\MatierePremiere;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMatierePremiereRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', MatierePremiere::class);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'categorie_id' => ['required', 'integer', 'exists:categories_matiere_premiere,id'],
            'unite_de_mesure_id' => ['required', 'integer', 'exists:unites_de_mesure,id'],
            'libelle' => ['required', 'string', 'max:255'],
            'seuil_alerte_stock' => ['nullable', 'numeric', 'min:0'],
            'gestion_peremption' => ['boolean'],
            'statut' => ['sometimes', Rule::enum(Statut::class)],
        ];
    }
}
