<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\CatalogueFournisseur;
use App\Models\User;

class CatalogueFournisseurPolicy
{
    public function before(User $user, string $_ability): ?bool
    {
        return $user->isSuperAdmin() ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return $user->can('catalogue-fournisseur.view');
    }

    public function view(User $user, CatalogueFournisseur $_catalogueFournisseur): bool
    {
        return $user->can('catalogue-fournisseur.view');
    }

    public function create(User $user): bool
    {
        return $user->can('catalogue-fournisseur.create');
    }

    public function update(User $user, CatalogueFournisseur $_catalogueFournisseur): bool
    {
        return $user->can('catalogue-fournisseur.update');
    }

    public function delete(User $user, CatalogueFournisseur $_catalogueFournisseur): bool
    {
        return $user->can('catalogue-fournisseur.delete');
    }
}
