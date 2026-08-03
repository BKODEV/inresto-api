<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Reception;
use App\Models\User;

/**
 * La permission dit *ce que* l'utilisateur peut faire, le périmètre dit *où*.
 * Confirmer une réception fait entrer du stock et impute une dépense au budget
 * du site : le contrôle ne peut donc pas se limiter au filtrage de l'index.
 * Le `before()` dispense le super-admin, non borné par principe.
 */
class ReceptionPolicy
{
    public function before(User $user, string $_ability): ?bool
    {
        return $user->isSuperAdmin() ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return $user->can('reception.view');
    }

    public function view(User $user, Reception $reception): bool
    {
        return $user->can('reception.view') && $user->appartientAuSite($reception->site_id);
    }

    public function create(User $user): bool
    {
        return $user->can('reception.create');
    }

    public function update(User $user, Reception $reception): bool
    {
        return $user->can('reception.update') && $user->appartientAuSite($reception->site_id);
    }

    public function delete(User $user, Reception $reception): bool
    {
        return $user->can('reception.delete') && $user->appartientAuSite($reception->site_id);
    }
}
