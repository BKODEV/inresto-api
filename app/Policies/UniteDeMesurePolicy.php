<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\UniteDeMesure;
use App\Models\User;

class UniteDeMesurePolicy
{
    public function before(User $user, string $_ability): ?bool
    {
        return $user->isSuperAdmin() ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return $user->can('unite-de-mesure.view');
    }

    public function view(User $user, UniteDeMesure $_uniteDeMesure): bool
    {
        return $user->can('unite-de-mesure.view');
    }

    public function create(User $user): bool
    {
        return $user->can('unite-de-mesure.create');
    }

    public function update(User $user, UniteDeMesure $_uniteDeMesure): bool
    {
        return $user->can('unite-de-mesure.update');
    }

    public function delete(User $user, UniteDeMesure $_uniteDeMesure): bool
    {
        return $user->can('unite-de-mesure.delete');
    }
}
