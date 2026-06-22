<?php

declare(strict_types=1);

namespace App\Http\Requests\ParametreEtablissement;

use App\Models\ParametreEtablissement;
use App\Models\Site;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreParametreEtablissementRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Site $site */
        $site = $this->route('site');

        return $this->user()->can(
            'update',
            ParametreEtablissement::firstOrNew(['site_id' => $site->id]),
        );
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'nom_commercial' => ['required', 'string', 'max:255'],
            'devise' => ['required', 'string', 'max:10'],
            'slogan' => ['nullable', 'string', 'max:255'],
            'adresse' => ['nullable', 'string'],
            'telephone' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'site_web' => ['nullable', 'string', 'max:255'],
            'raison_sociale' => ['nullable', 'string', 'max:255'],
            'ncc' => ['nullable', 'string', 'max:255'],
            'rccm' => ['nullable', 'string', 'max:255'],
            'regime_fiscal' => ['nullable', 'string', 'max:255'],
            'mentions_pied_page' => ['nullable', 'string'],
        ];
    }
}
