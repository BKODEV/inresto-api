<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Caisse;
use App\Models\User;

class CaissePolicy
{
    public function before(User $user, string $_ability): ?bool
    {
        return $user->isSuperAdmin() ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return $user->can('caisse.view');
    }

    public function view(User $user, Caisse $_caisse): bool
    {
        return $user->can('caisse.view');
    }

    public function create(User $user): bool
    {
        return $user->can('caisse.create');
    }

    public function update(User $user, Caisse $_caisse): bool
    {
        return $user->can('caisse.update');
    }

    public function delete(User $user, Caisse $_caisse): bool
    {
        return $user->can('caisse.delete');
    }
}
