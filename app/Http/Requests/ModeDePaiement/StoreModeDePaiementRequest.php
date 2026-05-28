<?php

declare(strict_types=1);

namespace App\Http\Requests\ModeDePaiement;

use App\Enums\Statut;
use App\Models\ModeDePaiement;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreModeDePaiementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', ModeDePaiement::class);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'libelle' => ['required', 'string', 'max:255'],
            'statut'  => ['sometimes', Rule::enum(Statut::class)],
        ];
    }
}
