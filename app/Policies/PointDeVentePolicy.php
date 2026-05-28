<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\PointDeVente;
use App\Models\User;

class PointDeVentePolicy
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
        return $user->can('point-de-vente.view');
    }

    public function view(User $user, PointDeVente $_pointDeVente): bool
    {
        return $user->can('point-de-vente.view');
    }

    public function create(User $user): bool
    {
        return $user->can('point-de-vente.create');
    }

    public function update(User $user, PointDeVente $_pointDeVente): bool
    {
        return $user->can('point-de-vente.update');
    }

    public function delete(User $user, PointDeVente $_pointDeVente): bool
    {
        return $user->can('point-de-vente.delete');
    }
}
