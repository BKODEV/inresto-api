<?php

declare(strict_types=1);

namespace App\Http\Requests\Role;

use App\Support\PermissionCatalog;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('role'));
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $roleId = $this->route('role')->getKey();
        $niveauMaxAutorise = $this->user()->niveauMax() - 1;

        return [
            'name' => ['sometimes', 'required', 'string', 'max:255', Rule::unique('roles', 'name')->ignore($roleId)],
            'description' => ['sometimes', 'nullable', 'string'],
            'niveau' => ['sometimes', 'required', 'integer', 'min:0', "max:{$niveauMaxAutorise}"],
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => ['string', Rule::in(PermissionCatalog::allPermissionNames())],
        ];
    }
}
