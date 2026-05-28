<?php

declare(strict_types=1);

namespace App\Http\Requests\UniteDeMesure;

use App\Enums\Statut;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUniteDeMesureRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('unite_de_mesure'));
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'libelle' => ['sometimes', 'string', 'max:255'],
            'symbole' => ['sometimes', 'string', 'max:10'],
            'statut'  => ['sometimes', Rule::enum(Statut::class)],
        ];
    }
}
