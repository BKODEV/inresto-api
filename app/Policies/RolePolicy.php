<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Role;
use App\Models\User;

/**
 * Autorisation du module Rôles. La consultation suit `role.view` ; la création,
 * la modification et la suppression sont réservées au super-admin (via before()),
 * car gérer les rôles touche aux fondations de sécurité de l'instance.
 */
class RolePolicy
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
        return $user->can('role.view');
    }

    public function view(User $user, Role $_role): bool
    {
        return $user->can('role.view');
    }

    public function create(User $_user): bool
    {
        return false;
    }

    public function update(User $_user, Role $_role): bool
    {
        return false;
    }

    public function delete(User $_user, Role $_role): bool
    {
        return false;
    }
}
