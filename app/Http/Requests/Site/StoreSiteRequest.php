<?php

declare(strict_types=1);

namespace App\Http\Requests\Site;

use App\Enums\Statut;
use App\Models\Site;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSiteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Site::class);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'nom' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:20', 'unique:sites,code'],
            'adresse' => ['nullable', 'string'],
            'statut' => ['sometimes', Rule::enum(Statut::class)],
        ];
    }
}
