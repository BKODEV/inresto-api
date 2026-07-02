<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 */
class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nom' => $this->nom,
            'prenom' => $this->prenom,
            'identifiant' => $this->identifiant,
            'email' => $this->email,
            'telephone' => $this->telephone,
            'photo' => $this->photo,
            'statut' => $this->statut,
            'a_pin' => $this->pin_code !== null,
            'last_login_at' => $this->last_login_at?->toIso8601String(),
            'niveau_max' => $this->whenLoaded('roles', fn () => $this->niveauMax()),
            'roles' => $this->whenLoaded('roles', fn () => $this->roles->map(fn (Role $role) => [
                'id' => $role->id,
                'name' => $role->name,
                'description' => $role->description,
                'niveau' => $role->niveau,
                'is_system' => $role->is_system,
            ])->values()),
            'sites' => $this->whenLoaded('sites', fn () => $this->sites->map(fn ($site) => [
                'id' => $site->id,
                'nom' => $site->nom,
                'code' => $site->code,
                'statut' => $site->statut->value,
            ])->values()),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
