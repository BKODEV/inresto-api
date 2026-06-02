<?php

declare(strict_types=1);

namespace App\Http\Requests\SousRecette;

use App\Models\SousRecette;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSousRecetteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', SousRecette::class);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'libelle' => ['required', 'string', 'max:255'],
            'quantite_produite' => ['required', 'numeric', 'gt:0'],
            'unite_id' => [
                'required',
                'integer',
                Rule::exists('unites_de_mesure', 'id')->where('statut', 'actif'),
            ],
            'lignes' => ['required', 'array', 'min:1'],
            // Une matière ne peut apparaître qu'une fois dans le tableau.
            'lignes.*.matiere_premiere_id' => [
                'required',
                'integer',
                'distinct',
                Rule::exists('matieres_premieres', 'id')->where('statut', 'actif'),
            ],
            'lignes.*.unite_id' => [
                'required',
                'integer',
                Rule::exists('unites_de_mesure', 'id')->where('statut', 'actif'),
            ],
            'lignes.*.quantite' => ['required', 'numeric', 'gt:0'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'lignes.*.matiere_premiere_id.distinct' => 'Une matière première ne peut apparaître qu\'une fois dans la sous-recette.',
        ];
    }
}
