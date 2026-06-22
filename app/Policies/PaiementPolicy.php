<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Paiement;
use App\Models\User;

class PaiementPolicy
{
    public function before(User $user, string $_ability): ?bool
    {
        return $user->isSuperAdmin() ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return $user->can('paiement.view');
    }

    public function view(User $user, Paiement $_paiement): bool
    {
        return $user->can('paiement.view');
    }

    public function create(User $user): bool
    {
        return $user->can('paiement.create');
    }
}
