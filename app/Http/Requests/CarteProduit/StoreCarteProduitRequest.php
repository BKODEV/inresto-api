<?php

declare(strict_types=1);

namespace App\Http\Requests\CarteProduit;

use App\Enums\Statut;
use App\Models\CarteProduit;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCarteProduitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', CarteProduit::class);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $carteId = $this->route('carte')->id;

        return [
            'produit_id' => [
                'required',
                'integer',
                // Produit inactif -> 422 (ne peut figurer sur une carte).
                Rule::exists('produits', 'id')->where('statut', Statut::Actif->value),
                // Un produit ne figure qu'une fois par carte -> 422.
                Rule::unique('carte_produit', 'produit_id')->where('carte_id', $carteId),
            ],
            'prix_vente' => ['required', 'numeric', 'gt:0'],
            'statut' => ['sometimes', Rule::enum(Statut::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'produit_id.unique' => 'Ce produit figure déjà sur cette carte.',
            'produit_id.exists' => 'Le produit est introuvable ou inactif.',
        ];
    }
}
