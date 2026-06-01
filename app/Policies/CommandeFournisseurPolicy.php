<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\CommandeFournisseur;
use App\Models\User;

class CommandeFournisseurPolicy
{
    public function before(User $user, string $_ability): ?bool
    {
        return $user->isSuperAdmin() ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return $user->can('commande-fournisseur.view');
    }

    public function view(User $user, CommandeFournisseur $_commande): bool
    {
        return $user->can('commande-fournisseur.view');
    }

    public function create(User $user): bool
    {
        return $user->can('commande-fournisseur.create');
    }

    public function update(User $user, CommandeFournisseur $_commande): bool
    {
        return $user->can('commande-fournisseur.update');
    }

    public function delete(User $user, CommandeFournisseur $_commande): bool
    {
        return $user->can('commande-fournisseur.delete');
    }
}
