<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\CategorieMatierePremiere;
use App\Models\User;

class CategorieMatierePremirePolicy
{
    public function before(User $user, string $_ability): ?bool
    {
        return $user->isSuperAdmin() ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return $user->can('categorie-matiere-premiere.view');
    }

    public function view(User $user, CategorieMatierePremiere $_categorieMatierePremiere): bool
    {
        return $user->can('categorie-matiere-premiere.view');
    }

    public function create(User $user): bool
    {
        return $user->can('categorie-matiere-premiere.create');
    }

    public function update(User $user, CategorieMatierePremiere $_categorieMatierePremiere): bool
    {
        return $user->can('categorie-matiere-premiere.update');
    }

    public function delete(User $user, CategorieMatierePremiere $_categorieMatierePremiere): bool
    {
        return $user->can('categorie-matiere-premiere.delete');
    }
}
