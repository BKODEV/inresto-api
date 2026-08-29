<?php

declare(strict_types=1);

namespace App\Http\Requests\SessionCuisine;

use App\Enums\StatutSessionCuisine;
use App\Models\SessionCuisine;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexSessionCuisineRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('viewAny', SessionCuisine::class);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'point_de_vente_id' => ['sometimes', 'integer', 'exists:points_de_vente,id'],
            'statut' => ['sometimes', Rule::enum(StatutSessionCuisine::class)],
            'date_debut' => ['sometimes', 'date'],
            'date_fin' => ['sometimes', 'date', 'after_or_equal:date_debut'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:200'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
