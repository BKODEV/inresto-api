<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\MatierePremiere;
use App\Models\User;

class MatierePremierePolicy
{
    public function before(User $user, string $_ability): ?bool
    {
        return $user->isSuperAdmin() ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return $user->can('matiere-premiere.view');
    }

    public function view(User $user, MatierePremiere $_matierePremiere): bool
    {
        return $user->can('matiere-premiere.view');
    }

    public function create(User $user): bool
    {
        return $user->can('matiere-premiere.create');
    }

    public function update(User $user, MatierePremiere $_matierePremiere): bool
    {
        return $user->can('matiere-premiere.update');
    }

    public function delete(User $user, MatierePremiere $_matierePremiere): bool
    {
        return $user->can('matiere-premiere.delete');
    }
}
