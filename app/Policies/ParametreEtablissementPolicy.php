<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ParametreEtablissement;
use App\Models\User;

class ParametreEtablissementPolicy
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
        return $user->can('parametre_etablissement.view');
    }

    public function view(User $user, ParametreEtablissement $_parametre): bool
    {
        return $user->can('parametre_etablissement.view');
    }

    public function create(User $user): bool
    {
        return $user->can('parametre_etablissement.create');
    }

    public function update(User $user, ParametreEtablissement $_parametre): bool
    {
        return $user->can('parametre_etablissement.update');
    }

    public function delete(User $user, ParametreEtablissement $_parametre): bool
    {
        return $user->can('parametre_etablissement.delete');
    }
}
