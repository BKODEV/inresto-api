<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\PosteCuisine;
use App\Models\User;

class PosteCuisinePolicy
{
    public function before(User $user, string $_ability): ?bool
    {
        return $user->isSuperAdmin() ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return $user->can('poste_cuisine.view');
    }

    public function view(User $user, PosteCuisine $_poste): bool
    {
        return $user->can('poste_cuisine.view');
    }

    public function create(User $user): bool
    {
        return $user->can('poste_cuisine.create');
    }

    public function update(User $user, PosteCuisine $_poste): bool
    {
        return $user->can('poste_cuisine.update');
    }

    public function delete(User $user, PosteCuisine $_poste): bool
    {
        return $user->can('poste_cuisine.delete');
    }
}
