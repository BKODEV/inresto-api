<?php

declare(strict_types=1);

namespace App\Http\Requests\FicheTechnique;

use App\Models\FicheTechnique;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreFicheTechniqueRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', FicheTechnique::class);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'produit_id' => ['required', 'integer', 'exists:produits,id'],

            'lignes_matieres' => ['sometimes', 'array'],
            'lignes_matieres.*.matiere_premiere_id' => [
                'required',
                'integer',
                'distinct',
                Rule::exists('matieres_premieres', 'id')->where('statut', 'actif'),
            ],
            'lignes_matieres.*.unite_id' => [
                'required',
                'integer',
                Rule::exists('unites_de_mesure', 'id')->where('statut', 'actif'),
            ],
            'lignes_matieres.*.quantite' => ['required', 'numeric', 'gt:0'],

            'lignes_sous_recettes' => ['sometimes', 'array'],
            'lignes_sous_recettes.*.sous_recette_id' => [
                'required',
                'integer',
                'distinct',
                'exists:sous_recettes,id',
            ],
            'lignes_sous_recettes.*.quantite' => ['required', 'numeric', 'gt:0'],
        ];
    }

    /**
     * Au moins une ligne (matière ou sous-recette) est requise.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $matieres = (array) $this->input('lignes_matieres', []);
            $sousRecettes = (array) $this->input('lignes_sous_recettes', []);

            if ($matieres === [] && $sousRecettes === []) {
                $validator->errors()->add(
                    'lignes_matieres',
                    'Une fiche technique doit comporter au moins une ligne (matière ou sous-recette).',
                );
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'lignes_matieres.*.matiere_premiere_id.distinct' => 'Une matière première ne peut apparaître qu\'une fois.',
            'lignes_sous_recettes.*.sous_recette_id.distinct' => 'Une sous-recette ne peut apparaître qu\'une fois.',
        ];
    }
}
