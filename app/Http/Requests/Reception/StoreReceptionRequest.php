<?php

declare(strict_types=1);

namespace App\Http\Requests\Reception;

use App\Models\Reception;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreReceptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Reception::class);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'commande_fournisseur_id' => [
                'required',
                'integer',
                // Seule une commande envoyée ou partiellement reçue peut être réceptionnée.
                Rule::exists('commandes_fournisseur', 'id')
                    ->whereIn('statut', ['envoyee', 'partiellement_recue']),
            ],
            // La cuisine destinataire : doit exister et être active.
            'site_id' => [
                'required',
                'integer',
                Rule::exists('sites', 'id')->where('statut', 'actif'),
            ],
            'date_reception' => ['nullable', 'date'],
            'observations' => ['nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'commande_fournisseur_id.exists' => 'La commande doit être envoyée ou partiellement reçue pour être réceptionnée.',
            'site_id.exists' => 'Le site destinataire est introuvable ou inactif.',
        ];
    }
}
