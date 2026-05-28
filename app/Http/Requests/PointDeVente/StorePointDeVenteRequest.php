<?php

declare(strict_types=1);

namespace App\Http\Requests\PointDeVente;

use App\Enums\PointDeVenteType;
use App\Enums\Statut;
use App\Models\PointDeVente;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePointDeVenteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', PointDeVente::class);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'nom'         => ['required', 'string', 'max:255'],
            'type'        => ['required', Rule::enum(PointDeVenteType::class)],
            'description' => ['nullable', 'string'],
            'statut'      => ['sometimes', Rule::enum(Statut::class)],
        ];
    }
}
