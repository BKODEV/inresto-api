<?php

use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
 // ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/**
 * Seede les permissions + rôles système (RBAC) dans la base de test.
 */
function seedRbac(): void
{
    test()->seed(PermissionSeeder::class);
    test()->seed(RoleSeeder::class);
}

/**
 * Crée un utilisateur portant un rôle système (les rôles doivent être seedés),
 * éventuellement rattaché à des sites, et l'authentifie via Sanctum.
 *
 * @param  int[]  $siteIds
 */
function actingAsRole(string $role, array $siteIds = []): User
{
    $user = User::factory()->create();
    $user->assignRole($role);

    if ($siteIds !== []) {
        $user->sites()->sync($siteIds);
    }

    Sanctum::actingAs($user->load(['roles', 'sites']));

    return $user;
}

/**
 * Crée et authentifie un super-admin (rôle système requis).
 */
function actingAsSuperAdmin(array $siteIds = []): User
{
    return actingAsRole(Role::SUPER_ADMIN, $siteIds);
}

/**
 * Rattache un utilisateur à des sites créés après son authentification.
 *
 * Le périmètre (`user_site`) borne toute lecture et toute écriture rattachée à
 * un site : sans rattachement, les listes reviennent vides et les écritures
 * sont refusées. La plupart des tests créant leurs sites après l'appel à
 * `actingAs…`, ce raccourci évite d'en réordonner le corps.
 */
function rattacherAuxSites(User $user, Site ...$sites): User
{
    $user->sites()->syncWithoutDetaching(collect($sites)->pluck('id')->all());

    return $user;
}

/**
 * Authentifie un utilisateur avec les permissions et le périmètre de sites
 * donnés, pour les tests du module Reporting.
 *
 * @param  string[]  $permissions
 * @param  int[]  $siteIds  Sites rattachés (pivot user_site).
 */
function actingAsReportingUser(array $permissions = [], array $siteIds = []): User
{
    $user = User::factory()->create();

    foreach ($permissions as $name) {
        Permission::findOrCreate($name, 'api');
    }
    $user->givePermissionTo($permissions);

    if ($siteIds !== []) {
        $user->sites()->sync($siteIds);
    }

    Sanctum::actingAs($user);

    return $user;
}
