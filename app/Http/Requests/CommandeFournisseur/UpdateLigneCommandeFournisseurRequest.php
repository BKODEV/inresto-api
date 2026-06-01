<?php

declare(strict_types=1);

namespace App\Http\Requests\CommandeFournisseur;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateLigneCommandeFournisseurRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('commande_fournisseur'));
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'matiere_premiere_id' => [
                'sometimes',
                'integer',
                Rule::exists('matieres_premieres', 'id')->where('statut', 'actif'),
            ],
            'unite_id' => [
                'sometimes',
                'integer',
                Rule::exists('unites_de_mesure', 'id')->where('statut', 'actif'),
            ],
            'quantite_commandee' => ['sometimes', 'numeric', 'gt:0'],
            'prix_unitaire_previsionnel' => ['sometimes', 'numeric', 'gt:0'],
        ];
    }
}
