<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Support\PermissionCatalog;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Crée toutes les permissions de l'application à partir de la source unique
 * {@see PermissionCatalog}. Idempotent : relançable sans duplication.
 */
class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (PermissionCatalog::allPermissionNames() as $name) {
            Permission::firstOrCreate([
                'name' => $name,
                'guard_name' => 'api',
            ]);
        }
    }
}
