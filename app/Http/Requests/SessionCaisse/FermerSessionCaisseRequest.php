<?php

declare(strict_types=1);

namespace App\Http\Requests\SessionCaisse;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FermerSessionCaisseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('session'));
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // Un constaté par contenant. Rien n'est obligatoire : tous les
            // modes ne sont pas vérifiables le soir même — un virement arrive à
            // la banque quand il arrive. Un mode laissé de côté ressort « non
            // rapproché » sur le rapport, au lieu d'un écart inventé.
            'rapprochements' => ['sometimes', 'array'],
            'rapprochements.*.mode_de_paiement_id' => [
                'required',
                'integer',
                Rule::exists('modes_de_paiement', 'id'),
            ],
            'rapprochements.*.montant_constate' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
