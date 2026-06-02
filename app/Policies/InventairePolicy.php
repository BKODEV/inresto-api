<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Inventaire;
use App\Models\User;

class InventairePolicy
{
    public function before(User $user, string $_ability): ?bool
    {
        return $user->isSuperAdmin() ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return $user->can('inventaire.view');
    }

    public function view(User $user, Inventaire $_inventaire): bool
    {
        return $user->can('inventaire.view');
    }

    public function create(User $user): bool
    {
        return $user->can('inventaire.create');
    }

    public function update(User $user, Inventaire $_inventaire): bool
    {
        return $user->can('inventaire.update');
    }

    public function delete(User $user, Inventaire $_inventaire): bool
    {
        return $user->can('inventaire.delete');
    }
}
