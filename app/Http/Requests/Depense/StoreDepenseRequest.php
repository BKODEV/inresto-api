<?php

declare(strict_types=1);

namespace App\Http\Requests\Depense;

use App\Models\Depense;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreDepenseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Depense::class);
    }

    /**
     * `type` et `reception_id` ne sont jamais fournis par le client : une saisie
     * manuelle est toujours une charge hors stock, forcée côté serveur.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'libelle' => ['required', 'string', 'max:255'],
            'montant' => ['required', 'numeric', 'gt:0'],
            'date_depense' => ['required', 'date'],
        ];
    }
}
