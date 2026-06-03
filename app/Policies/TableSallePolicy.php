<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\TableSalle;
use App\Models\User;

class TableSallePolicy
{
    public function before(User $user, string $_ability): ?bool
    {
        return $user->isSuperAdmin() ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return $user->can('table.view');
    }

    public function view(User $user, TableSalle $_table): bool
    {
        return $user->can('table.view');
    }

    public function create(User $user): bool
    {
        return $user->can('table.create');
    }

    public function update(User $user, TableSalle $_table): bool
    {
        return $user->can('table.update');
    }

    public function delete(User $user, TableSalle $_table): bool
    {
        return $user->can('table.delete');
    }
}
