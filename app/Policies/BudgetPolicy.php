<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Budget;
use App\Models\User;

class BudgetPolicy
{
    public function before(User $user, string $_ability): ?bool
    {
        return $user->isSuperAdmin() ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return $user->can('budget.view');
    }

    public function view(User $user, Budget $_budget): bool
    {
        return $user->can('budget.view');
    }

    public function create(User $user): bool
    {
        return $user->can('budget.create');
    }

    public function update(User $user, Budget $_budget): bool
    {
        return $user->can('budget.update');
    }

    public function delete(User $user, Budget $_budget): bool
    {
        return $user->can('budget.delete');
    }
}
