<?php

declare(strict_types=1);

namespace App\Http\Requests\Caisse;

use App\Enums\Statut;
use App\Models\Caisse;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCaisseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Caisse::class);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'point_de_vente_id' => ['required', 'integer', 'exists:points_de_vente,id'],
            'libelle'           => ['required', 'string', 'max:255'],
            'statut'            => ['sometimes', Rule::enum(Statut::class)],
        ];
    }
}
