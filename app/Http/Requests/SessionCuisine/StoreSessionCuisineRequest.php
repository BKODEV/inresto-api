<?php

declare(strict_types=1);

namespace App\Http\Requests\SessionCuisine;

use App\Models\SessionCuisine;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSessionCuisineRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', SessionCuisine::class);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'point_de_vente_id' => ['required', 'integer', 'exists:points_de_vente,id'],
            'libelle' => ['nullable', 'string', 'max:60'],
            'notes' => ['nullable', 'string', 'max:1000'],
            // La brigade est facultative à l'ouverture : on lance le service et
            // on complète quand les commis arrivent. Qui ouvre y est ajouté
            // d'office par le service.
            'brigade' => ['sometimes', 'array', 'max:30'],
            'brigade.*' => ['required', 'uuid', 'distinct', Rule::exists('users', 'id')],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'brigade.*.distinct' => 'Cette personne figure déjà dans la brigade.',
        ];
    }
}
