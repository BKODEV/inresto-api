<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ModeDePaiement;
use App\Models\User;

class ModeDePaiementPolicy
{
    public function before(User $user, string $_ability): ?bool
    {
        return $user->isSuperAdmin() ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return $user->can('mode-de-paiement.view');
    }

    public function view(User $user, ModeDePaiement $_modeDePaiement): bool
    {
        return $user->can('mode-de-paiement.view');
    }

    public function create(User $user): bool
    {
        return $user->can('mode-de-paiement.create');
    }

    public function update(User $user, ModeDePaiement $_modeDePaiement): bool
    {
        return $user->can('mode-de-paiement.update');
    }

    public function delete(User $user, ModeDePaiement $_modeDePaiement): bool
    {
        return $user->can('mode-de-paiement.delete');
    }
}
