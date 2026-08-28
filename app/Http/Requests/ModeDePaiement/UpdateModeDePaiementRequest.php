<?php

declare(strict_types=1);

namespace App\Http\Requests\ModeDePaiement;

use App\Enums\Statut;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateModeDePaiementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('mode_de_paiement'));
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'libelle' => ['sometimes', 'string', 'max:255'],
            // L'argent de ce mode se retrouve-t-il dans le tiroir compté à la
            // fermeture ? Seuls les modes marqués entrent dans le théorique de
            // la session.
            'compte_en_caisse' => ['sometimes', 'boolean'],
            // Ordre des boutons à la caisse, et départage entre plusieurs modes
            // en tiroir pour la présélection. Absent ou <= 0 = placé en fin.
            'ordre' => ['sometimes', 'integer', 'min:0'],
            'statut' => ['sometimes', Rule::enum(Statut::class)],
        ];
    }
}
