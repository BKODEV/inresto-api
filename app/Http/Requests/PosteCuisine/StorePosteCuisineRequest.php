<?php

declare(strict_types=1);

namespace App\Http\Requests\PosteCuisine;

use App\Enums\Statut;
use App\Http\Requests\PosteCuisine\Concerns\ValideLeRattachementDuPoste;
use App\Models\PosteCuisine;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePosteCuisineRequest extends FormRequest
{
    use ValideLeRattachementDuPoste;

    public function authorize(): bool
    {
        return $this->user()->can('create', PosteCuisine::class);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'point_de_vente_id' => ['required', 'integer', 'exists:points_de_vente,id'],
            'libelle' => [
                'required',
                'string',
                'max:255',
                // Deux postes homonymes sur un même pass seraient indiscernables.
                Rule::unique('postes_cuisine')->where(
                    fn ($query) => $query->where('point_de_vente_id', $this->input('point_de_vente_id'))
                ),
            ],
            'ordre' => ['sometimes', 'integer', 'min:0'],
            'statut' => ['sometimes', Rule::enum(Statut::class)],
            // Le rattachement des catégories se pose dès la création : un poste
            // sans catégorie ne filtre rien et n'apparaît vide à l'écran. Une
            // catégorie déjà produite par un autre poste du pass est refusée.
            ...$this->reglesCategories(),
            // Qui tient le poste, aux mêmes conditions que les catégories.
            ...$this->reglesMembres(),
        ];
    }
}
