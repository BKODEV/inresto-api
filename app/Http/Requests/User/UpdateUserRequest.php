<?php

declare(strict_types=1);

namespace App\Http\Requests\User;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('user'));
    }

    /**
     * Le mot de passe ne se modifie pas ici : il passe par les endpoints dédiés
     * de réinitialisation (réservés à l'administrateur).
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $userId = $this->route('user')->getKey();

        return [
            'nom' => ['sometimes', 'required', 'string', 'max:255'],
            'prenom' => ['sometimes', 'required', 'string', 'max:255'],
            'identifiant' => ['sometimes', 'required', 'string', 'max:255', Rule::unique('users', 'identifiant')->ignore($userId)],
            'email' => ['sometimes', 'nullable', 'email', 'max:255', Rule::unique('users', 'email')->ignore($userId)],
            'telephone' => ['sometimes', 'nullable', 'string', 'max:50'],
            'pin_code' => ['sometimes', 'nullable', 'string', 'max:20'],
            'statut' => ['sometimes', Rule::in(['actif', 'inactif'])],
            'roles' => ['sometimes', 'array'],
            'roles.*' => ['string', 'exists:roles,name'],
            'sites' => ['sometimes', 'array'],
            'sites.*' => ['integer', 'exists:sites,id'],
        ];
    }
}
