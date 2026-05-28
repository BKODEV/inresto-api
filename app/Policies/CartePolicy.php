<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Carte;
use App\Models\User;

class CartePolicy
{
    public function before(User $user, string $_ability): ?bool
    {
        return $user->isSuperAdmin() ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return $user->can('carte.view');
    }

    public function view(User $user, Carte $_carte): bool
    {
        return $user->can('carte.view');
    }

    public function create(User $user): bool
    {
        return $user->can('carte.create');
    }

    public function update(User $user, Carte $_carte): bool
    {
        return $user->can('carte.update');
    }

    public function delete(User $user, Carte $_carte): bool
    {
        return $user->can('carte.delete');
    }
}
