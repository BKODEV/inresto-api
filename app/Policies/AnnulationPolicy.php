<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Annulation;
use App\Models\User;

class AnnulationPolicy
{
    public function before(User $user, string $_ability): ?bool
    {
        return $user->isSuperAdmin() ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return $user->can('annulation.view');
    }

    public function view(User $user, Annulation $_annulation): bool
    {
        return $user->can('annulation.view');
    }

    public function create(User $user): bool
    {
        return $user->can('annulation.create');
    }
}
