<?php

declare(strict_types=1);

namespace App\Http\Requests\SousRecette;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSousRecetteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('sous_recette'));
    }

    /**
     * Mise à jour = remplacement complet (entête + sync des lignes).
     *
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
