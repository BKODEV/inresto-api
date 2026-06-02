<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Reception;
use App\Models\User;

class ReceptionPolicy
{
    public function before(User $user, string $_ability): ?bool
    {
        return $user->isSuperAdmin() ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return $user->can('reception.view');
    }

    public function view(User $user, Reception $_reception): bool
    {
        return $user->can('reception.view');
    }

    public function create(User $user): bool
    {
        return $user->can('reception.create');
    }

    public function update(User $user, Reception $_reception): bool
    {
        return $user->can('reception.update');
    }

    public function delete(User $user, Reception $_reception): bool
    {
        return $user->can('reception.delete');
    }
}
