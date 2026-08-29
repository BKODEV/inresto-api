<?php

declare(strict_types=1);

namespace App\Http\Requests\SessionCuisine;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FermerSessionCuisineRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('fermer', $this->route('session_cuisine'));
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'notes' => ['nullable', 'string', 'max:1000'],
            // La brigade se corrige à la fermeture : c'est là qu'on sait qui a
            // réellement tenu le service, remplacements compris.
            'brigade' => ['sometimes', 'array', 'max:30'],
            'brigade.*' => ['required', 'uuid', 'distinct', Rule::exists('users', 'id')],
        ];
    }
}
