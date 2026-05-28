<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\CategorieProduit;
use App\Models\User;

class CategorieProduitPolicy
{
    public function before(User $user, string $_ability): ?bool
    {
        return $user->isSuperAdmin() ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return $user->can('categorie-produit.view');
    }

    public function view(User $user, CategorieProduit $_categorieProduit): bool
    {
        return $user->can('categorie-produit.view');
    }

    public function create(User $user): bool
    {
        return $user->can('categorie-produit.create');
    }

    public function update(User $user, CategorieProduit $_categorieProduit): bool
    {
        return $user->can('categorie-produit.update');
    }

    public function delete(User $user, CategorieProduit $_categorieProduit): bool
    {
        return $user->can('categorie-produit.delete');
    }
}
