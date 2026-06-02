<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\SousRecette;
use App\Models\User;

class SousRecettePolicy
{
    public function before(User $user, string $_ability): ?bool
    {
        return $user->isSuperAdmin() ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return $user->can('sousrecette.view');
    }

    public function view(User $user, SousRecette $_sousRecette): bool
    {
        return $user->can('sousrecette.view');
    }

    public function create(User $user): bool
    {
        return $user->can('sousrecette.create');
    }

    public function update(User $user, SousRecette $_sousRecette): bool
    {
        return $user->can('sousrecette.update');
    }

    public function delete(User $user, SousRecette $_sousRecette): bool
    {
        return $user->can('sousrecette.delete');
    }
}
