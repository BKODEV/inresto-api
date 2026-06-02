<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Depense;
use App\Models\User;

class DepensePolicy
{
    public function before(User $user, string $_ability): ?bool
    {
        return $user->isSuperAdmin() ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return $user->can('depense.view');
    }

    public function view(User $user, Depense $_depense): bool
    {
        return $user->can('depense.view');
    }

    public function create(User $user): bool
    {
        return $user->can('depense.create');
    }

    public function update(User $user, Depense $_depense): bool
    {
        return $user->can('depense.update');
    }

    public function delete(User $user, Depense $_depense): bool
    {
        return $user->can('depense.delete');
    }
}
