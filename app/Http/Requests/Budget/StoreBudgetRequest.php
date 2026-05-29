<?php

declare(strict_types=1);

namespace App\Http\Requests\Budget;

use App\Models\Budget;
use Carbon\Carbon;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBudgetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Budget::class);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'point_de_vente_id' => [
                'required',
                'integer',
                'exists:points_de_vente,id',
                Rule::unique('budgets')->where(
                    fn ($query) => $query->whereDate('mois', $this->input('mois'))
                ),
            ],
            'mois' => [
                'required',
                'date',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (Carbon::parse($value)->day !== 1) {
                        $fail('Le champ mois doit correspondre au premier jour du mois.');
                    }
                },
            ],
            'montant_alloue' => ['required', 'numeric', 'min:0'],
            'seuil_alerte_75' => ['boolean'],
            'seuil_alerte_90' => ['boolean'],
            'seuil_alerte_100' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'point_de_vente_id.unique' => 'Un budget existe déjà pour ce point de vente sur ce mois.',
        ];
    }
}
