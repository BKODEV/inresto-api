<?php

declare(strict_types=1);

namespace App\Http\Requests\Fournisseur;

use App\Enums\Statut;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateFournisseurRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('fournisseur'));
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'nom' => ['sometimes', 'string', 'max:255'],
            'telephone' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'adresse' => ['nullable', 'string', 'max:255'],
            'conditions_paiement' => ['nullable', 'string'],
            'delai_livraison_jours' => ['nullable', 'integer', 'min:1'],
            'statut' => ['sometimes', Rule::enum(Statut::class)],
        ];
    }
}
