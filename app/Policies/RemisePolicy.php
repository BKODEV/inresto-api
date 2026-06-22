<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Remise;
use App\Models\User;

class RemisePolicy
{
    public function before(User $user, string $_ability): ?bool
    {
        return $user->isSuperAdmin() ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return $user->can('remise.view');
    }

    public function view(User $user, Remise $_remise): bool
    {
        return $user->can('remise.view');
    }

    public function create(User $user): bool
    {
        return $user->can('remise.create');
    }
}
