<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Budget;
use App\Models\User;

/**
 * La permission dit *ce que* l'utilisateur peut faire, le périmètre dit *où*.
 * Un budget est propre à un site : sans ce contrôle, un accès direct par
 * identifiant contournerait le bornage de l'index.
 * Le `before()` dispense le super-admin, non borné par principe.
 */
class BudgetPolicy
{
    public function before(User $user, string $_ability): ?bool
    {
        return $user->isSuperAdmin() ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return $user->can('budget.view');
    }

    public function view(User $user, Budget $budget): bool
    {
        return $user->can('budget.view') && $user->appartientAuSite($budget->site_id);
    }

    public function create(User $user): bool
    {
        return $user->can('budget.create');
    }

    public function update(User $user, Budget $budget): bool
    {
        return $user->can('budget.update') && $user->appartientAuSite($budget->site_id);
    }

    public function delete(User $user, Budget $budget): bool
    {
        return $user->can('budget.delete') && $user->appartientAuSite($budget->site_id);
    }
}
