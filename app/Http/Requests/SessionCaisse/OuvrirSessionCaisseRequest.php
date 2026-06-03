<?php

declare(strict_types=1);

namespace App\Http\Requests\SessionCaisse;

use App\Models\SessionCaisse;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class OuvrirSessionCaisseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', SessionCaisse::class);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'caisse_id' => ['required', 'integer', 'exists:caisses,id'],
            'fonds_initial' => ['sometimes', 'numeric', 'min:0'],
        ];
    }
}
