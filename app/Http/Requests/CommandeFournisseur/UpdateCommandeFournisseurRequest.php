<?php

declare(strict_types=1);

namespace App\Http\Requests\CommandeFournisseur;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateCommandeFournisseurRequest extends FormRequest
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
            'fournisseur_id' => ['sometimes', 'integer', 'exists:fournisseurs,id'],
            'date_commande' => ['sometimes', 'date'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
