<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Services\UserService;

/**
 * Autorisation du module Utilisateurs. La hiérarchie fine (anti-escalade) et la
 * protection du dernier super-admin sont appliquées dans {@see UserService} ;
 * la policy gère les permissions et le périmètre de visibilité.
 */
class UserPolicy
{
    public function before(User $user, string $_ability): ?bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        return null;
    }

    public function viewAny(User $user): bool
    {
        return $user->can('utilisateur.view');
    }

    public function view(User $user, User $cible): bool
    {
        if (! $user->can('utilisateur.view')) {
            return false;
        }

        // Périmètre : on ne consulte que soi-même ou un utilisateur de ses sites.
        return $user->getKey() === $cible->getKey() || $user->partageSiteAvec($cible);
    }

    public function create(User $user): bool
    {
        return $user->can('utilisateur.create');
    }

    public function update(User $user, User $_cible): bool
    {
        return $user->can('utilisateur.update');
    }

    public function delete(User $user, User $_cible): bool
    {
        return $user->can('utilisateur.delete');
    }
}
