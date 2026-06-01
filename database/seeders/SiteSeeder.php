<?php

namespace Database\Seeders;

use App\Models\PointDeVente;
use App\Models\Site;
use Illuminate\Database\Seeder;

/**
 * Crée le site par défaut et y rattache les points de vente orphelins.
 *
 * Exécuté en production : garantit qu'au moins un site existe pour ne pas
 * casser les données antérieures au passage multi-site (Lot 1).
 */
class SiteSeeder extends Seeder
{
    public function run(): void
    {
        $site = Site::query()->firstOrCreate(
            ['code' => 'PRINCIPAL'],
            ['nom' => 'Site principal', 'statut' => 'actif']
        );

        // Rattache les points de vente qui ne seraient liés à aucun site.
        PointDeVente::query()
            ->whereNull('site_id')
            ->update(['site_id' => $site->id]);
    }
}
