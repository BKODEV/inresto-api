<?php

declare(strict_types=1);

namespace App\Http\Requests\Produit;

use App\Enums\Statut;
use App\Models\Produit;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProduitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Produit::class);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'categorie_id' => ['required', 'integer', 'exists:categories_produit,id'],
            'libelle' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'est_menu' => ['sometimes', 'boolean'],
            'photo' => ['nullable', 'string', 'max:255'],
            'allergenes' => ['nullable', 'string'],
            'statut' => ['sometimes', Rule::enum(Statut::class)],
        ];
    }
}
