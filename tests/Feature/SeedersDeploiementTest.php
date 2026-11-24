<?php

declare(strict_types=1);

use App\Models\CategorieMatierePremiere;
use App\Models\CategorieProduit;
use App\Models\Fournisseur;
use App\Models\MatierePremiere;
use App\Models\Role;
use App\Models\Site;
use App\Models\UniteDeMesure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

use function Pest\Laravel\putJson;

uses(RefreshDatabase::class);

/**
 * db:seed tourne à chaque déploiement : il ne doit ni dupliquer, ni écraser,
 * ni ressusciter ce que l'exploitation a modifié.
 */
function compterReferentiels(): array
{
    return [
        Permission::query()->count(),
        Role::query()->count(),
        Site::query()->count(),
        CategorieProduit::query()->count(),
        CategorieMatierePremiere::query()->count(),
        UniteDeMesure::query()->count(),
        MatierePremiere::withTrashed()->count(),
        Fournisseur::withTrashed()->count(),
    ];
}

it('ne crée rien de plus quand on relance db:seed', function (): void {
    test()->seed();
    $avant = compterReferentiels();

    test()->seed();

    expect(compterReferentiels())->toBe($avant);
});

it('ne recrée pas une matière ou un fournisseur supprimé en prod', function (): void {
    test()->seed();
    MatierePremiere::query()->where('libelle', 'Tomate')->firstOrFail()->delete();
    Fournisseur::query()->where('nom', 'Marché de gros Abidjan')->firstOrFail()->delete();

    test()->seed();

    expect(MatierePremiere::query()->where('libelle', 'Tomate')->exists())->toBeFalse()
        ->and(Fournisseur::query()->where('nom', 'Marché de gros Abidjan')->exists())->toBeFalse();
});

it('ne recrée pas un référentiel ou le site renommés en prod', function (): void {
    test()->seed();
    CategorieProduit::query()->where('libelle', 'Plats')->update(['libelle' => 'Plats chauds']);
    UniteDeMesure::query()->where('symbole', 'pce')->update(['symbole' => 'u', 'libelle' => 'Unité']);
    Site::query()->where('code', 'PRINCIPAL')->update(['code' => 'MARCORY', 'nom' => 'Innoplaza Marcory']);

    test()->seed();

    expect(CategorieProduit::query()->where('libelle', 'Plats')->exists())->toBeFalse()
        ->and(UniteDeMesure::query()->where('symbole', 'pce')->exists())->toBeFalse()
        ->and(Site::query()->pluck('code')->all())->toBe(['MARCORY']);
});

it('conserve les réglages faits en prod sur un rôle système', function (): void {
    seedRbac();
    $gerant = Role::findByName('gerant', 'api');
    $gerant->update(['description' => 'Responsable du site', 'niveau' => 45]);
    $gerant->revokePermissionTo('parametre.view');

    seedRbac();

    $gerant->refresh();
    expect($gerant->description)->toBe('Responsable du site')
        ->and($gerant->niveau)->toBe(45)
        ->and($gerant->hasPermissionTo('parametre.view'))->toBeFalse();
});

it('donne aux rôles existants les seules permissions nouvelles du code', function (): void {
    seedRbac();
    $gerant = Role::findByName('gerant', 'api');
    $gerant->revokePermissionTo('commande_client.view');
    // Simule une permission ajoutée au catalogue depuis le dernier déploiement.
    Permission::findByName('parametre.view', 'api')->delete();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    seedRbac();

    $gerant = Role::findByName('gerant', 'api');
    expect($gerant->hasPermissionTo('parametre.view'))->toBeTrue()
        ->and($gerant->hasPermissionTo('commande_client.view'))->toBeFalse()
        ->and(Role::findByName(Role::SUPER_ADMIN, 'api')->permissions()->count())->toBe(Permission::query()->count());
});

it('refuse de renommer un rôle système', function (): void {
    seedRbac();
    actingAsSuperAdmin();
    $gerant = Role::findByName('gerant', 'api');

    putJson("/api/roles/{$gerant->id}", ['name' => 'manager'])->assertUnprocessable()->assertJsonValidationErrors('name');
    putJson("/api/roles/{$gerant->id}", ['name' => 'gerant', 'description' => 'Responsable'])->assertOk();
});
