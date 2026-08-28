<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Models\Versement;

/**
 * Sortir de l'argent du tiroir est un geste distinct de l'encaissement : il
 * mérite sa propre permission, sans quoi tout caissier pourrait vider la caisse
 * du seul fait qu'il sait y encaisser.
 */
class VersementPolicy
{
    public function before(User $user, string $_ability): ?bool
    {
        return $user->isSuperAdmin() ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return $user->can('versement.view');
    }

    public function view(User $user, Versement $_versement): bool
    {
        return $user->can('versement.view');
    }

    public function create(User $user): bool
    {
        return $user->can('versement.create');
    }
}
