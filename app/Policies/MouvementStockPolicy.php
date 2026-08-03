<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\MouvementStock;
use App\Models\User;

/**
 * Journal des mouvements : strictement en lecture. Un mouvement est immuable
 * (la table ne porte même pas d'`updated_at`) et n'est écrit que par le
 * StockService. Même permission que l'état du stock : `stock.view`.
 */
class MouvementStockPolicy
{
    public function before(User $user, string $_ability): ?bool
    {
        return $user->isSuperAdmin() ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return $user->can('stock.view');
    }

    public function view(User $user, MouvementStock $_mouvement): bool
    {
        return $user->can('stock.view');
    }
}
