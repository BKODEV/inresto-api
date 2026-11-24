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
 *
 * Retient les permissions créées par ce passage : {@see RoleSeeder} ne donne
 * aux rôles déjà en place que celles-là, sans toucher aux réglages faits en prod.
 */
class PermissionSeeder extends Seeder
{
    /**
     * Permissions créées lors du dernier passage.
     *
     * @var list<string>
     */
    public static array $creees = [];

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        self::$creees = [];

        foreach (PermissionCatalog::allPermissionNames() as $name) {
            $permission = Permission::firstOrCreate([
                'name' => $name,
                'guard_name' => 'api',
            ]);

            if ($permission->wasRecentlyCreated) {
                self::$creees[] = $name;
            }
        }
    }
}
