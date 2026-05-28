<?php

declare(strict_types=1);

namespace App\Http\Requests\CategorieMatierePremiere;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateCategorieMatierePremireRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('categorie_matiere_premire'));
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'libelle'     => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
        ];
    }
}
