<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\SessionCuisine;
use App\Models\User;

/**
 * Ouvrir et fermer sont deux droits distincts, comme pour la caisse : un commis
 * lance le service du matin, seul le chef le clôt — c'est la fermeture qui fige
 * ce que la brigade a produit.
 */
class SessionCuisinePolicy
{
    public function before(User $user, string $_ability): ?bool
    {
        return $user->isSuperAdmin() ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return $user->can('session_cuisine.view');
    }

    public function view(User $user, SessionCuisine $_session): bool
    {
        return $user->can('session_cuisine.view');
    }

    public function create(User $user): bool
    {
        return $user->can('session_cuisine.ouvrir');
    }

    public function fermer(User $user, SessionCuisine $_session): bool
    {
        return $user->can('session_cuisine.fermer');
    }
}
