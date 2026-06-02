<?php

declare(strict_types=1);

namespace App\Http\Requests\Inventaire;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLigneInventaireRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('inventaire'));
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'matiere_premiere_id' => [
                'required',
                'integer',
                Rule::exists('matieres_premieres', 'id')->where('statut', 'actif'),
            ],
            // Zéro autorisé : compter zéro est une information valide.
            'quantite_physique' => ['required', 'numeric', 'gte:0'],
        ];
    }
}
