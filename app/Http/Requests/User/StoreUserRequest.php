<?php

declare(strict_types=1);

namespace App\Http\Requests\User;

use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', User::class);
    }

    /**
     * Validation conditionnelle du mot de passe : si l'admin n'en saisit pas,
     * l'email devient requis (un mot de passe provisoire y est envoyé).
     *
     * Le rattachement à au moins un site est obligatoire : le périmètre borne
     * les lectures et les écritures rattachées à un site (stock, inventaire,
     * réception, budget). Un compte sans site n'aurait accès à rien.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'nom' => ['required', 'string', 'max:255'],
            'prenom' => ['required', 'string', 'max:255'],
            'identifiant' => ['required', 'string', 'max:255', 'unique:users,identifiant'],
            'email' => ['nullable', 'email', 'max:255', 'unique:users,email', 'required_without:password'],
            'password' => ['nullable', 'string', 'min:8', 'required_without:email'],
            'telephone' => ['nullable', 'string', 'max:50'],
            'pin_code' => ['nullable', 'string', 'max:20'],
            'statut' => ['sometimes', Rule::in(['actif', 'inactif'])],
            'roles' => ['array'],
            'roles.*' => ['string', 'exists:roles,name'],
            'sites' => ['required', 'array', 'min:1'],
            'sites.*' => ['integer', 'exists:sites,id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.required_without' => 'Un email est requis lorsqu\'aucun mot de passe n\'est fourni.',
            'password.required_without' => 'Un mot de passe est requis si aucun email n\'est fourni.',
            'sites.required' => 'Rattachez le compte à au moins un site.',
            'sites.min' => 'Rattachez le compte à au moins un site.',
        ];
    }
}
