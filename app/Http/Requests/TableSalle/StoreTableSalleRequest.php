<?php

declare(strict_types=1);

namespace App\Http\Requests\TableSalle;

use App\Enums\StatutTable;
use App\Models\TableSalle;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTableSalleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', TableSalle::class);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'point_de_vente_id' => ['required', 'integer', 'exists:points_de_vente,id'],
            'numero' => [
                'required',
                'string',
                'max:50',
                // Numéro unique par point de vente -> 422.
                Rule::unique('tables_salle', 'numero')->where('point_de_vente_id', $this->input('point_de_vente_id')),
            ],
            'capacite' => ['required', 'integer', 'min:1'],
            'statut' => ['sometimes', Rule::enum(StatutTable::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'numero.unique' => 'Ce numéro de table existe déjà sur ce point de vente.',
        ];
    }
}
