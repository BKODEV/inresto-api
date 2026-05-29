<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Fournisseur;
use App\Models\User;

class FournisseurPolicy
{
    public function before(User $user, string $_ability): ?bool
    {
        return $user->isSuperAdmin() ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return $user->can('fournisseur.view');
    }

    public function view(User $user, Fournisseur $_fournisseur): bool
    {
        return $user->can('fournisseur.view');
    }

    public function create(User $user): bool
    {
        return $user->can('fournisseur.create');
    }

    public function update(User $user, Fournisseur $_fournisseur): bool
    {
        return $user->can('fournisseur.update');
    }

    public function delete(User $user, Fournisseur $_fournisseur): bool
    {
        return $user->can('fournisseur.delete');
    }
}
