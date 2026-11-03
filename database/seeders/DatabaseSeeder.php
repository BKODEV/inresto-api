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
        // RoleSeeder après PermissionSeeder (assigne des permissions existantes).
        // SuperAdminSeeder est volontairement HORS de cette liste : db:seed tourne à
        // chaque déploiement et il remettrait le mot de passe du super-admin à sa
        // valeur par défaut. À lancer à la main, une fois, après DatabaseSeeder :
        //   php artisan db:seed --class=SuperAdminSeeder
        // Les seeders de nomenclature (catégories, unités) posent les référentiels
        // usuels d'un restaurant pour démarrer sur des données réelles.
        $this->call([
            PermissionSeeder::class,
            RoleSeeder::class,
            SiteSeeder::class,
            CategorieProduitSeeder::class,
            CategorieMatierePremiereSeeder::class,
            UniteDeMesureSeeder::class,
            // MatierePremiereSeeder après ses référentiels (catégories + unités).
            MatierePremiereSeeder::class,
            FournisseurSeeder::class,
        ]);
    }
}
