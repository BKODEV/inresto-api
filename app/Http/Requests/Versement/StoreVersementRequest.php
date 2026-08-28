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
            // De quel solde cet argent se retire. Obligatoire pour un
            // reversement de pourboires, interdit ailleurs : posé par erreur sur
            // un dépôt bancaire, il effacerait silencieusement la dette d'un
            // serveur.
            'beneficiaire_id' => [
                Rule::requiredIf(fn (): bool => $this->input('destination') === DestinationVersement::Personnel->value),
                Rule::prohibitedIf(fn (): bool => $this->input('destination') !== DestinationVersement::Personnel->value),
                'uuid',
                Rule::exists('users', 'id'),
            ],
            // Qui a EMPORTÉ l'argent : une société de collecte, un convoyeur, un
            // comptable. Du texte libre, car ces gens-là n'ont pas de compte.
            'remis_a' => ['nullable', 'string', 'max:255'],
            // Bordereau de dépôt, pour un versement en banque.
            'reference' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'beneficiaire_id.required' => 'Indiquez à quel membre du personnel ce pourboire est reversé.',
            'beneficiaire_id.prohibited' => 'Un bénéficiaire ne se désigne que pour un reversement de pourboires.',
        ];
    }
}
