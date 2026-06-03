<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\CarteProduit;
use App\Models\User;

class CarteProduitPolicy
{
    public function before(User $user, string $_ability): ?bool
    {
        return $user->isSuperAdmin() ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return $user->can('carte_produit.view');
    }

    public function view(User $user, CarteProduit $_carteProduit): bool
    {
        return $user->can('carte_produit.view');
    }

    public function create(User $user): bool
    {
        return $user->can('carte_produit.create');
    }

    public function update(User $user, CarteProduit $_carteProduit): bool
    {
        return $user->can('carte_produit.update');
    }

    public function delete(User $user, CarteProduit $_carteProduit): bool
    {
        return $user->can('carte_produit.delete');
    }
}
