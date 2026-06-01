<?php

declare(strict_types=1);

namespace App\Http\Requests\CatalogueFournisseur;

use App\Models\CatalogueFournisseur;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCatalogueFournisseurRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', CatalogueFournisseur::class);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'fournisseur_id' => [
                'required',
                'integer',
                'exists:fournisseurs,id',
                Rule::unique('catalogue_fournisseur')->where(
                    fn ($query) => $query->where('matiere_premiere_id', $this->input('matiere_premiere_id'))
                ),
            ],
            'matiere_premiere_id' => ['required', 'integer', 'exists:matieres_premieres,id'],
            'prix_unitaire' => ['required', 'numeric', 'min:0'],
            'unite_achat_id' => ['required', 'integer', 'exists:unites_de_mesure,id'],
            // Combien d'unités de stockage dans 1 unité d'achat (> 0 : sert de diviseur à la réception).
            'qte_par_unite_achat' => ['required', 'numeric', 'gt:0'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'fournisseur_id.unique' => 'Ce fournisseur est déjà associé à cette matière première.',
        ];
    }
}
