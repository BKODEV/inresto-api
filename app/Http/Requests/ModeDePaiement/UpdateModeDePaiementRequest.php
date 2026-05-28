<?php

declare(strict_types=1);

namespace App\Http\Requests\ModeDePaiement;

use App\Enums\Statut;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateModeDePaiementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('mode_de_paiement'));
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'libelle' => ['sometimes', 'string', 'max:255'],
            'statut'  => ['sometimes', Rule::enum(Statut::class)],
        ];
    }
}
