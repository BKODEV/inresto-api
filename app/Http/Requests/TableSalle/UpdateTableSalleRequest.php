<?php

declare(strict_types=1);

namespace App\Http\Requests\TableSalle;

use App\Models\TableSalle;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTableSalleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('table'));
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var TableSalle $table */
        $table = $this->route('table');

        return [
            'numero' => [
                'sometimes',
                'string',
                'max:50',
                Rule::unique('tables_salle', 'numero')
                    ->where('point_de_vente_id', $table->point_de_vente_id)
                    ->ignore($table->id),
            ],
            'capacite' => ['sometimes', 'integer', 'min:1'],
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
