<?php

declare(strict_types=1);

namespace App\Http\Requests\Inventaire;

use App\Models\Inventaire;
use App\Rules\SiteDansPerimetre;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreInventaireRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Inventaire::class);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // La clôture applique les écarts au stock de ce site : il doit
            // relever du périmètre de celui qui compte.
            'site_id' => [
                'required',
                'integer',
                Rule::exists('sites', 'id')->where('statut', 'actif'),
                new SiteDansPerimetre($this->user()),
            ],
            'date_inventaire' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
