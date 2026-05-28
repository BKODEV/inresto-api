<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'identifiant' => ['required', 'string'],
            'password'    => ['required', 'string'],
            'device_name' => ['sometimes', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'identifiant.required' => 'L\'identifiant est obligatoire.',
            'identifiant.string'   => 'L\'identifiant doit être une chaîne de caractères.',
            'password.required'    => 'Le mot de passe est obligatoire.',
            'password.string'      => 'Le mot de passe doit être une chaîne de caractères.',
            'device_name.string'   => 'Le nom de l\'appareil doit être une chaîne de caractères.',
            'device_name.max'      => 'Le nom de l\'appareil ne doit pas dépasser :max caractères.',
        ];
    }
}
