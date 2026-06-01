<?php

declare(strict_types=1);

namespace App\Http\Requests\PointDeVente;

use App\Enums\PointDeVenteType;
use App\Enums\Statut;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePointDeVenteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('point_de_vente'));
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'site_id' => ['sometimes', 'integer', 'exists:sites,id'],
            'nom' => ['sometimes', 'string', 'max:255'],
            'type' => ['sometimes', Rule::enum(PointDeVenteType::class)],
            'description' => ['nullable', 'string'],
            'statut' => ['sometimes', Rule::enum(Statut::class)],
        ];
    }
}
