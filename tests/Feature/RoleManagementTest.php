<?php

declare(strict_types=1);

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\deleteJson;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

uses(RefreshDatabase::class);

beforeEach(function () {
    seedRbac();
});

it('expose le catalogue de permissions groupé par module avec libellés', function () {
    actingAsRole('gerant'); // possède role.view

    $modules = getJson('/api/permissions')->assertOk()->json('data.modules');

    expect($modules)->toBeArray()->not->toBeEmpty();

    $toutes = collect($modules)->flatMap(fn ($m) => collect($m['permissions'])->pluck('name'));
    $libelles = collect($modules)->flatMap(fn ($m) => collect($m['permissions'])->pluck('libelle'));

    expect($toutes)->toContain('utilisateur.create')
        ->and($libelles)->toContain('Créer les utilisateurs')
        ->and(collect($modules)->pluck('module'))->toContain('Utilisateurs & Rôles');
});

it('refuse /permissions et /roles sans la permission role.view', function () {
    actingAsRole('serveur'); // pas de role.view

    getJson('/api/permissions')->assertForbidden();
    getJson('/api/roles')->assertForbidden();
});

it('liste les rôles avec le nombre d\'utilisateurs', function () {
    actingAsSuperAdmin();

    $roles = getJson('/api/roles')->assertOk()->json('data.roles');

    expect(collect($roles)->pluck('name'))->toContain('gerant')
        ->and(collect($roles)->firstWhere('name', 'gerant'))->toHaveKey('users_count');
});

it('permet au super-admin de créer un rôle de niveau inférieur', function () {
    actingAsSuperAdmin();

    postJson('/api/roles', [
        'name' => 'maitre-hotel',
        'description' => 'Coordination de salle',
        'niveau' => 40,
        'permissions' => ['table.view', 'commande_client.view'],
    ])->assertCreated()
        ->assertJsonPath('data.is_system', false)
        ->assertJsonPath('data.niveau', 40);

    expect(Role::where('name', 'maitre-hotel')->first()->permissions()->count())->toBe(2);
});

it('refuse un niveau de rôle supérieur ou égal à celui du créateur', function () {
    actingAsSuperAdmin();

    postJson('/api/roles', [
        'name' => 'trop-haut',
        'niveau' => 100, // >= 100 (super-admin)
        'permissions' => [],
    ])->assertStatus(422);
});

it('interdit à un non-super-admin de créer un rôle', function () {
    actingAsRole('gerant'); // a role.view mais pas la gestion des rôles

    postJson('/api/roles', [
        'name' => 'bidon',
        'niveau' => 5,
    ])->assertForbidden();
});

it('refuse la suppression d\'un rôle système (409)', function () {
    actingAsSuperAdmin();

    $gerant = Role::where('name', 'gerant')->first();

    deleteJson("/api/roles/{$gerant->id}")->assertStatus(409);
    expect(Role::where('name', 'gerant')->exists())->toBeTrue();
});

it('refuse la suppression d\'un rôle encore porté par des utilisateurs (409)', function () {
    actingAsSuperAdmin();

    $role = Role::create(['name' => 'temporaire', 'guard_name' => 'api', 'niveau' => 5, 'is_system' => false]);
    User::factory()->create()->assignRole($role);

    deleteJson("/api/roles/{$role->id}")->assertStatus(409);
});

it('permet de supprimer un rôle non système et inutilisé', function () {
    actingAsSuperAdmin();

    $role = Role::create(['name' => 'jetable', 'guard_name' => 'api', 'niveau' => 5, 'is_system' => false]);

    deleteJson("/api/roles/{$role->id}")->assertOk();
    expect(Role::where('name', 'jetable')->exists())->toBeFalse();
});
