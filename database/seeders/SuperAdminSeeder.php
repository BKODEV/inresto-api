<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\PermissionRegistrar;

class SuperAdminSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $role = Role::firstOrCreate([
            'name' => 'super-admin',
            'guard_name' => 'api',
        ]);

        $user = User::updateOrCreate(
            ['identifiant' => 'superadmin'],
            [
                'nom' => 'Super',
                'prenom' => 'Admin',
                'email' => 'superadmin@inresto.local',
                'password' => Hash::make('SuperAdmin@2026'),
                'statut' => 'actif',
            ],
        );

        $user->syncRoles([$role]);

        // Rattachement au site principal (PRINCIPAL créé par SiteSeeder).
        $site = Site::query()->firstOrCreate(
            ['code' => 'PRINCIPAL'],
            ['nom' => 'Site principal', 'statut' => 'actif']
        );

        $user->sites()->syncWithoutDetaching([
            $site->id => ['est_principal' => true],
        ]);
    }
}
