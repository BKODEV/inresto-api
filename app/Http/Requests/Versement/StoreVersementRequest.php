<?php

declare(strict_types=1);

namespace App\Http\Requests\Versement;

use App\Enums\DestinationVersement;
use App\Models\Versement;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreVersementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Versement::class);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'montant' => ['required', 'numeric', 'gt:0'],
            'destination' => ['required', Rule::enum(DestinationVersement::class)],
            // Qui a reçu l'argent : le coffre ne signe pas de reçu, une
            // personne si.
            'remis_a' => ['nullable', 'string', 'max:255'],
            // Bordereau de dépôt, pour un versement en banque.
            'reference' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
