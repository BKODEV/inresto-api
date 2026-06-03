<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Produit;
use App\Models\User;

class ProduitPolicy
{
    public function before(User $user, string $_ability): ?bool
    {
        return $user->isSuperAdmin() ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return $user->can('produit.view');
    }

    public function view(User $user, Produit $_produit): bool
    {
        return $user->can('produit.view');
    }

    public function create(User $user): bool
    {
        return $user->can('produit.create');
    }

    public function update(User $user, Produit $_produit): bool
    {
        return $user->can('produit.update');
    }

    public function delete(User $user, Produit $_produit): bool
    {
        return $user->can('produit.delete');
    }
}
