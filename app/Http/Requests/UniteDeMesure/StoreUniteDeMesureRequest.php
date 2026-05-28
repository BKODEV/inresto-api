<?php

declare(strict_types=1);

namespace App\Http\Requests\UniteDeMesure;

use App\Enums\Statut;
use App\Models\UniteDeMesure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreUniteDeMesureRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', UniteDeMesure::class);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'libelle' => ['required', 'string', 'max:255'],
            'symbole' => ['required', 'string', 'max:10'],
            'statut'  => ['sometimes', Rule::enum(Statut::class)],
        ];
    }
}
