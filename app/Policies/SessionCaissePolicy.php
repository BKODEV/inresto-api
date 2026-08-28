<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\SessionCaisse;
use App\Models\User;

class SessionCaissePolicy
{
    public function before(User $user, string $_ability): ?bool
    {
        return $user->isSuperAdmin() ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return $user->can('session_caisse.view');
    }

    public function view(User $user, SessionCaisse $_session): bool
    {
        return $user->can('session_caisse.view');
    }

    /**
     * Ouvrir une session de caisse.
     */
    public function create(User $user): bool
    {
        return $user->can('session_caisse.create');
    }

    /**
     * Fermer une session de caisse.
     */
    public function update(User $user, SessionCaisse $_session): bool
    {
        return $user->can('session_caisse.update');
    }

    /**
     * Fermer sans que tout soit compté.
     *
     * Distincte de `update` à dessein : savoir fermer une caisse ne doit pas
     * suffire à passer outre le contrôle. C'est la porte de sortie d'un
     * responsable, pas un raccourci de fin de service.
     */
    public function forcer(User $user, SessionCaisse $_session): bool
    {
        return $user->can('session_caisse.forcer_fermeture');
    }
}
