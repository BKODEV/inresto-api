<?php

declare(strict_types=1);

namespace App\Http\Requests\Site;

use App\Enums\Statut;
use App\Models\Site;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSiteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('site'));
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var Site $site */
        $site = $this->route('site');

        return [
            'nom' => ['sometimes', 'string', 'max:255'],
            'code' => ['sometimes', 'string', 'max:20', Rule::unique('sites', 'code')->ignore($site->id)],
            'adresse' => ['nullable', 'string'],
            'statut' => ['sometimes', Rule::enum(Statut::class)],
        ];
    }
}
