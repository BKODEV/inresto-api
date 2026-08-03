<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Stock;
use App\Models\User;

/**
 * Le stock est en lecture seule côté API : il est dérivé des mouvements et
 * n'est écrit que par le StockService. Aucune capacité create/update/delete.
 */
class StockPolicy
{
    public function before(User $user, string $_ability): ?bool
    {
        return $user->isSuperAdmin() ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return $user->can('stock.view');
    }

    public function view(User $user, Stock $_stock): bool
    {
        return $user->can('stock.view');
    }
}
