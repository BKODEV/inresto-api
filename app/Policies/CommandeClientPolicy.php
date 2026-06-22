<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\CommandeClient;
use App\Models\User;

class CommandeClientPolicy
{
    public function before(User $user, string $_ability): ?bool
    {
        return $user->isSuperAdmin() ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return $user->can('commande_client.view');
    }

    public function view(User $user, CommandeClient $_commande): bool
    {
        return $user->can('commande_client.view');
    }

    public function create(User $user): bool
    {
        return $user->can('commande_client.create');
    }

    /**
     * Couvre l'ajout de lignes et les transitions (envoi cuisine, service).
     */
    public function update(User $user, CommandeClient $_commande): bool
    {
        return $user->can('commande_client.update');
    }

    public function delete(User $user, CommandeClient $_commande): bool
    {
        return $user->can('commande_client.delete');
    }

    public function encaisser(User $user, CommandeClient $_commande): bool
    {
        return $user->can('commande_client.encaisser');
    }
}
