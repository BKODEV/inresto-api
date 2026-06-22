<?php

declare(strict_types=1);

namespace App\Http\Requests\CarteProduit;

use App\Enums\Statut;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCarteProduitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('carte_produit'));
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'prix_vente' => ['sometimes', 'numeric', 'gt:0'],
            'statut' => ['sometimes', Rule::enum(Statut::class)],
            'ordre' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
