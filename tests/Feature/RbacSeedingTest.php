<?php

declare(strict_types=1);

use App\Models\Role;
use App\Models\User;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

it('crée toutes les permissions du catalogue, sans duplication en relançant', function () {
    seedRbac();
    $attendu = count(PermissionCatalog::allPermissionNames());
    expect(Permission::count())->toBe($attendu);

    // Idempotence : relancer ne duplique rien.
    seedRbac();
    expect(Permission::count())->toBe($attendu)
        ->and(Role::count())->toBe(6);
});

it('crée les 6 rôles système avec niveau, description et is_system', function () {
    seedRbac();

    $gerant = Role::where('name', 'gerant')->first();
    expect($gerant->niveau)->toBe(50)
        ->and($gerant->is_system)->toBeTrue()
        ->and($gerant->description)->not->toBeNull();

    expect(Role::where('name', Role::SUPER_ADMIN)->first()->niveau)->toBe(100)
        ->and(Role::where('name', 'serveur')->first()->niveau)->toBe(10);
});

it('donne toutes les permissions au super-admin', function () {
    seedRbac();

    $superAdmin = Role::where('name', Role::SUPER_ADMIN)->first();
    expect($superAdmin->permissions()->count())->toBe(Permission::count());
});

it('expose une permission dédiée pour déléguer l\'accès aux Paramètres', function () {
    // La section Paramètres s'ouvre sur `parametre.view`, indépendamment des
    // permissions de chaque écran qu'elle contient : c'est ce qui permet au
    // super-admin de déléguer la configuration sans céder son rôle.
    seedRbac();

    expect(PermissionCatalog::allPermissionNames())->toContain('parametre.view')
        ->and(Role::where('name', 'gerant')->first()->hasPermissionTo('parametre.view'))->toBeTrue();
});

it('installe une instance neuve et crée le premier super-admin via inresto:install', function () {
    $this->artisan('inresto:install', [
        '--identifiant' => 'admin',
        '--password' => 'Secret@2026',
        '--nom' => 'Admin',
        '--prenom' => 'Root',
        '--email' => 'admin@demo.local',
        '--no-interaction' => true,
    ])->assertExitCode(0);

    $user = User::where('identifiant', 'admin')->first();
    expect($user)->not->toBeNull()
        ->and($user->hasRole(Role::SUPER_ADMIN))->toBeTrue()
        ->and($user->sites()->count())->toBeGreaterThan(0);

    // Idempotent : relancer ne casse pas l'instance.
    $this->artisan('inresto:install', [
        '--identifiant' => 'admin',
        '--password' => 'Secret@2026',
        '--nom' => 'Admin',
        '--prenom' => 'Root',
        '--no-interaction' => true,
    ])->assertExitCode(0);

    expect(User::where('identifiant', 'admin')->count())->toBe(1);
});
