<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Site;
use App\Models\User;

class SitePolicy
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
        return $user->can('site.view');
    }

    public function view(User $user, Site $_site): bool
    {
        return $user->can('site.view');
    }

    public function create(User $user): bool
    {
        return $user->can('site.create');
    }

    public function update(User $user, Site $_site): bool
    {
        return $user->can('site.update');
    }

    public function delete(User $user, Site $_site): bool
    {
        return $user->can('site.delete');
    }
}
