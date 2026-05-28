<?php

declare(strict_types=1);

namespace App\Http\Requests\CategorieMatierePremiere;

use App\Models\CategorieMatierePremiere;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreCategorieMatierePremireRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', CategorieMatierePremiere::class);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'libelle'     => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
        ];
    }
}
