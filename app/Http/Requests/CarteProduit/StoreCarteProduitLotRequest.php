<?php

declare(strict_types=1);

namespace App\Http\Requests\CarteProduit;

use App\Enums\Statut;
use App\Models\CarteProduit;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCarteProduitLotRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', CarteProduit::class);
    }

    /**
     * Ajout en lot : mêmes règles que l'ajout unitaire, appliquées ligne à ligne.
     *
     * `distinct` s'ajoute à l'unicité en base : sans lui, deux fois le même
     * produit dans une seule requête passerait la règle `unique` (qui interroge
     * l'état d'avant la requête) et créerait un doublon sur la carte.
     *
     * Pas de champ `ordre` : un lot se pose en fin de section, dans l'ordre reçu.
     * Choisir une position n'a de sens que pour une ligne à la fois.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $carteId = $this->route('carte')->id;

        return [
            'lignes' => ['required', 'array', 'min:1'],
            'lignes.*.produit_id' => [
                'required',
                'integer',
                'distinct',
                Rule::exists('produits', 'id')->where('statut', Statut::Actif->value),
                Rule::unique('carte_produit', 'produit_id')->where('carte_id', $carteId),
            ],
            'lignes.*.prix_vente' => ['required', 'numeric', 'gt:0'],
            'lignes.*.statut' => ['sometimes', Rule::enum(Statut::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'lignes.required' => 'Sélectionnez au moins un produit.',
            'lignes.*.produit_id.distinct' => 'Ce produit est sélectionné deux fois.',
            'lignes.*.produit_id.unique' => 'Ce produit figure déjà sur cette carte.',
            'lignes.*.produit_id.exists' => 'Le produit est introuvable ou inactif.',
            'lignes.*.prix_vente.gt' => 'Le prix de vente doit être supérieur à zéro.',
        ];
    }
}
