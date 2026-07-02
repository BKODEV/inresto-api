<?php

declare(strict_types=1);

namespace App\Http\Requests\Role;

use App\Models\Role;
use App\Support\PermissionCatalog;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Role::class);
    }

    /**
     * Le niveau du nouveau rôle doit rester strictement inférieur à celui du
     * créateur (anti-escalade). Les permissions doivent exister au catalogue.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $niveauMaxAutorise = $this->user()->niveauMax() - 1;

        return [
            'name' => ['required', 'string', 'max:255', 'unique:roles,name'],
            'description' => ['nullable', 'string'],
            'niveau' => ['required', 'integer', 'min:0', "max:{$niveauMaxAutorise}"],
            'permissions' => ['array'],
            'permissions.*' => ['string', Rule::in(PermissionCatalog::allPermissionNames())],
        ];
    }
}
