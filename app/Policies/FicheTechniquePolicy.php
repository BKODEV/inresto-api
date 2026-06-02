<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\FicheTechnique;
use App\Models\User;

class FicheTechniquePolicy
{
    public function before(User $user, string $_ability): ?bool
    {
        return $user->isSuperAdmin() ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return $user->can('fichetechnique.view');
    }

    public function view(User $user, FicheTechnique $_fiche): bool
    {
        return $user->can('fichetechnique.view');
    }

    public function create(User $user): bool
    {
        return $user->can('fichetechnique.create');
    }

    public function update(User $user, FicheTechnique $_fiche): bool
    {
        return $user->can('fichetechnique.update');
    }

    public function delete(User $user, FicheTechnique $_fiche): bool
    {
        return $user->can('fichetechnique.delete');
    }
}
