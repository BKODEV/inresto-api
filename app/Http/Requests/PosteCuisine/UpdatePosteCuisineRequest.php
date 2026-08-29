<?php

declare(strict_types=1);

namespace App\Http\Requests\PosteCuisine;

use App\Enums\Statut;
use App\Http\Requests\PosteCuisine\Concerns\ValideLesMembresDuPoste;
use App\Models\PosteCuisine;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePosteCuisineRequest extends FormRequest
{
    use ValideLesMembresDuPoste;

    public function authorize(): bool
    {
        /** @var PosteCuisine $poste */
        $poste = $this->route('poste_cuisine');

        return $this->user()->can('update', $poste);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var PosteCuisine $poste */
        $poste = $this->route('poste_cuisine');

        return [
            // Le point de vente n'est pas modifiable : déplacer un poste d'un
            // pass à l'autre emporterait ses catégories dans un contexte où
            // elles n'ont pas le même sens. On en crée un autre.
            'libelle' => [
                'sometimes',
                'string',
                'max:255',
                Rule::unique('postes_cuisine')
                    ->where(fn ($query) => $query->where('point_de_vente_id', $poste->point_de_vente_id))
                    ->ignore($poste->id),
            ],
            'ordre' => ['sometimes', 'integer', 'min:0'],
            'statut' => ['sometimes', Rule::enum(Statut::class)],
            // Absent = rattachement inchangé ; tableau vide = on détache tout.
            'categories' => ['sometimes', 'array'],
            'categories.*' => ['integer', 'exists:categories_produit,id'],
            ...$this->reglesMembres(),
        ];
    }
}
