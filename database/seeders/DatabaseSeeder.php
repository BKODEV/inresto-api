<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        // Seeders = données par défaut RÉELLES uniquement (pas de factory).
        // Les données de démonstration se génèrent via : php artisan inresto:demo
        // SiteSeeder avant SuperAdminSeeder : le super-admin est rattaché au
        // site PRINCIPAL, qui doit donc déjà exister.
        $this->call([
            PermissionSeeder::class,
            SiteSeeder::class,
            SuperAdminSeeder::class,
        ]);
    }
}
