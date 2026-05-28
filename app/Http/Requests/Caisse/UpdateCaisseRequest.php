<?php

declare(strict_types=1);

namespace App\Http\Requests\Caisse;

use App\Enums\Statut;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCaisseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('caisse'));
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'point_de_vente_id' => ['sometimes', 'integer', 'exists:points_de_vente,id'],
            'libelle'           => ['sometimes', 'string', 'max:255'],
            'statut'            => ['sometimes', Rule::enum(Statut::class)],
        ];
    }
}
