<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

class PermissionSeeder extends Seeder
{
    /**
     * Liste de toutes les permissions, groupées par feature.
     *
     * @var array<string, string[]>
     */
    private array $permissions = [
        'site' => ['view', 'create', 'update', 'delete'],
        'point-de-vente' => ['view', 'create', 'update', 'delete'],
        'categorie-produit' => ['view', 'create', 'update', 'delete'],
        'categorie-matiere-premiere' => ['view', 'create', 'update', 'delete'],
        'unite-de-mesure' => ['view', 'create', 'update', 'delete'],
        'mode-de-paiement' => ['view', 'create', 'update', 'delete'],
        'caisse' => ['view', 'create', 'update', 'delete'],
        'carte' => ['view', 'create', 'update', 'delete'],
        'produit' => ['view', 'create', 'update', 'delete'],
        'carte_produit' => ['view', 'create', 'update', 'delete'],
        'table' => ['view', 'create', 'update', 'delete'],
        'session_caisse' => ['view', 'create', 'update', 'delete', 'utiliser'],
        'fournisseur' => ['view', 'create', 'update', 'delete'],
        'matiere-premiere' => ['view', 'create', 'update', 'delete'],
        'catalogue-fournisseur' => ['view', 'create', 'update', 'delete'],
        'commande-fournisseur' => ['view', 'create', 'update', 'delete'],
        'reception' => ['view', 'create', 'update', 'delete'],
        'budget' => ['view', 'create', 'update', 'delete'],
        'depense' => ['view', 'create', 'update', 'delete'],
        'sousrecette' => ['view', 'create', 'update', 'delete'],
        'fichetechnique' => ['view', 'create', 'update', 'delete'],
        'inventaire' => ['view', 'create', 'update', 'delete'],
    ];

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach ($this->permissions as $feature => $actions) {
            foreach ($actions as $action) {
                Permission::firstOrCreate([
                    'name' => "{$feature}.{$action}",
                    'guard_name' => 'api',
                ]);
            }
        }
    }
}
