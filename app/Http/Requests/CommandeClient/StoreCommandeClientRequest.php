<?php

declare(strict_types=1);

namespace App\Http\Requests\CommandeClient;

use App\Models\CommandeClient;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreCommandeClientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', CommandeClient::class);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'table_id' => ['required', 'integer', 'exists:tables_salle,id'],
            'session_caisse_id' => ['required', 'integer', 'exists:sessions_caisse,id'],
            // reservation_id accepté sans vérification de cohérence (V1).
            'reservation_id' => ['nullable', 'integer'],
            'appartement_id' => ['nullable', 'string', 'max:255'],
            'appartement_nom' => ['nullable', 'string', 'max:255'],
            'appartement_a_verifier' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
