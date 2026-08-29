<?php

declare(strict_types=1);

namespace App\Http\Requests\FicheTechnique;

use App\Enums\TypePointCritique;
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
            // Pas d'unité au contrat : elle est TOUJOURS l'unité de stockage de
            // la matière, estampée côté serveur (aucune conversion dimensionnelle
            // dans les calculs de coût / déstockage).
            'lignes_matieres.*.quantite' => ['required', 'numeric', 'gt:0'],

            'lignes_sous_recettes' => ['sometimes', 'array'],
            'lignes_sous_recettes.*.sous_recette_id' => [
                'required',
                'integer',
                'distinct',
                'exists:sous_recettes,id',
            ],
            'lignes_sous_recettes.*.quantite' => ['required', 'numeric', 'gt:0'],

            // Méthodologie. Pas d'`ordre` au contrat : il vient de la position
            // dans le tableau, sinon deux lignes au même rang rendraient la
            // fiche imprimée non déterministe.
            'etapes' => ['sometimes', 'array', 'max:50'],
            'etapes.*.description' => ['required', 'string', 'max:1000'],
            // Bornée à 24 h : au-delà, c'est une durée de maturation qui relève
            // d'une note, pas d'un minuteur qu'on suit pendant un service.
            'etapes.*.duree_minutes' => ['nullable', 'integer', 'min:1', 'max:1440'],

            'materiels' => ['sometimes', 'array', 'max:30'],
            'materiels.*' => ['required', 'string', 'max:120', 'distinct'],

            'points_critiques' => ['sometimes', 'array', 'max:20'],
            'points_critiques.*.type' => ['required', Rule::enum(TypePointCritique::class)],
            'points_critiques.*.description' => ['required', 'string', 'max:500'],
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
            'materiels.*.distinct' => 'Ce matériel est déjà listé.',
            'points_critiques.*.type.Illuminate\\Validation\\Rules\\Enum' => 'Type de point critique inconnu.',
        ];
    }
}
