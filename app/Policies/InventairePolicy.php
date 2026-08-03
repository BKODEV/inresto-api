<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Inventaire;
use App\Models\User;

/**
 * La permission dit *ce que* l'utilisateur peut faire, le périmètre dit *où*.
 * Filtrer la liste ne suffirait pas : sans ce contrôle, un accès direct par
 * identifiant (`/api/inventaires/{id}`) contournerait le bornage de l'index.
 * Le `before()` dispense le super-admin, non borné par principe.
 */
class InventairePolicy
{
    public function before(User $user, string $_ability): ?bool
    {
        return $user->isSuperAdmin() ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return $user->can('inventaire.view');
    }

    public function view(User $user, Inventaire $inventaire): bool
    {
        return $user->can('inventaire.view') && $user->appartientAuSite($inventaire->site_id);
    }

    public function create(User $user): bool
    {
        return $user->can('inventaire.create');
    }

    public function update(User $user, Inventaire $inventaire): bool
    {
        return $user->can('inventaire.update') && $user->appartientAuSite($inventaire->site_id);
    }

    public function delete(User $user, Inventaire $inventaire): bool
    {
        return $user->can('inventaire.delete') && $user->appartientAuSite($inventaire->site_id);
    }
}
