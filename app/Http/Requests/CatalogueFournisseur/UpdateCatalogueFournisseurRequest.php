<?php

declare(strict_types=1);

namespace App\Http\Requests\CatalogueFournisseur;

use App\Models\CatalogueFournisseur;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCatalogueFournisseurRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('catalogue_fournisseur'));
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var CatalogueFournisseur $ligne */
        $ligne = $this->route('catalogue_fournisseur');

        $fournisseurId = $this->input('fournisseur_id', $ligne->fournisseur_id);
        $matierePremiereId = $this->input('matiere_premiere_id', $ligne->matiere_premiere_id);

        return [
            'fournisseur_id' => [
                'sometimes',
                'integer',
                'exists:fournisseurs,id',
                Rule::unique('catalogue_fournisseur')
                    ->where(fn ($query) => $query->where('matiere_premiere_id', $matierePremiereId))
                    ->ignore($ligne->id),
            ],
            'matiere_premiere_id' => ['sometimes', 'integer', 'exists:matieres_premieres,id'],
            'prix_unitaire' => ['sometimes', 'numeric', 'min:0'],
            'unite_achat_id' => ['sometimes', 'integer', 'exists:unites_de_mesure,id'],
            'qte_par_unite_achat' => ['sometimes', 'numeric', 'gt:0'],
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
