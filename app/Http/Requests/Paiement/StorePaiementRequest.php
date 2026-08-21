<?php

declare(strict_types=1);

namespace App\Http\Requests\Paiement;

use App\Enums\Statut;
use App\Models\Paiement;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePaiementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Paiement::class);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'mode_de_paiement_id' => [
                'required',
                'integer',
                Rule::exists('modes_de_paiement', 'id')->where('statut', Statut::Actif->value),
            ],
            'montant' => ['required', 'numeric', 'gt:0'],
            // Espèces tendues. `gte:montant` : on ne rend pas plus que ce qu'on
            // a reçu, et une saisie inférieure au montant encaissé trahit une
            // inversion des deux champs.
            'montant_recu' => ['nullable', 'numeric', 'gte:montant'],
            'reference_externe' => ['nullable', 'string', 'max:255'],
        ];
    }
}
