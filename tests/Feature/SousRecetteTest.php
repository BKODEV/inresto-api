<?php

declare(strict_types=1);

use App\Models\LigneSousRecette;
use App\Models\MatierePremiere;
use App\Models\Site;
use App\Models\SousRecette;
use App\Models\Stock;
use App\Models\UniteDeMesure;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;

use function Pest\Laravel\deleteJson;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;
use function Pest\Laravel\putJson;

uses(RefreshDatabase::class);

/**
 * @param  string[]  $permissions
 */
function actingAsSousRecetteUser(array $permissions = []): User
{
    $user = User::factory()->create();
    foreach ($permissions as $name) {
        Permission::findOrCreate($name, 'api');
    }
    $user->givePermissionTo($permissions);
    Sanctum::actingAs($user);

    return $user;
}

/**
 * Crée une matière dotée d'un PMP moyen donné (une ligne de stock à ce PMP).
 */
function matiereAvecPmp(float $pmp): MatierePremiere
{
    $matiere = MatierePremiere::factory()->create();

    if ($pmp > 0) {
        Stock::factory()->create([
            'matiere_premiere_id' => $matiere->id,
            'site_id' => Site::factory()->create()->id,
            'pmp' => $pmp,
        ]);
    }

    return $matiere;
}

// ───────────────────────── création / coût ─────────────────────────

it('crée une sous-recette avec ses lignes et calcule le coût matière', function () {
    actingAsSousRecetteUser(['sousrecette.create']);
    $unite = UniteDeMesure::factory()->create();
    $matiereA = matiereAvecPmp(10); // 2 × 10 = 20
    $matiereB = matiereAvecPmp(5);  // 4 × 5  = 20

    postJson('/api/sous-recettes', [
        'libelle' => 'Sauce tomate',
        'quantite_produite' => 5,
        'unite_id' => $unite->id,
        'lignes' => [
            ['matiere_premiere_id' => $matiereA->id, 'unite_id' => $unite->id, 'quantite' => 2],
            ['matiere_premiere_id' => $matiereB->id, 'unite_id' => $unite->id, 'quantite' => 4],
        ],
    ])
        ->assertCreated()
        ->assertJsonPath('data.cout_matiere', '40.00')
        ->assertJsonCount(2, 'data.lignes');
});

it('utilise la moyenne des PMP de tous les sites', function () {
    actingAsSousRecetteUser(['sousrecette.create']);
    $unite = UniteDeMesure::factory()->create();
    $matiere = MatierePremiere::factory()->create();
    // Deux sites : PMP 10 et 20 -> moyenne 15. Ligne 2 -> 30.
    Stock::factory()->create(['matiere_premiere_id' => $matiere->id, 'site_id' => Site::factory()->create()->id, 'pmp' => 10]);
    Stock::factory()->create(['matiere_premiere_id' => $matiere->id, 'site_id' => Site::factory()->create()->id, 'pmp' => 20]);

    postJson('/api/sous-recettes', [
        'libelle' => 'Test moyenne',
        'quantite_produite' => 1,
        'unite_id' => $unite->id,
        'lignes' => [
            ['matiere_premiere_id' => $matiere->id, 'unite_id' => $unite->id, 'quantite' => 2],
        ],
    ])
        ->assertCreated()
        ->assertJsonPath('data.cout_matiere', '30.00');
});

it('met à 0 le coût matière d\'une matière sans stock sur aucun site', function () {
    actingAsSousRecetteUser(['sousrecette.create']);
    $unite = UniteDeMesure::factory()->create();
    $matiere = MatierePremiere::factory()->create(); // aucun stock

    postJson('/api/sous-recettes', [
        'libelle' => 'Sans stock',
        'quantite_produite' => 1,
        'unite_id' => $unite->id,
        'lignes' => [
            ['matiere_premiere_id' => $matiere->id, 'unite_id' => $unite->id, 'quantite' => 3],
        ],
    ])
        ->assertCreated()
        ->assertJsonPath('data.cout_matiere', '0.00');
});

// ───────────────────────── mise à jour (sync) ─────────────────────────

it('recalcule le coût matière à la mise à jour des lignes', function () {
    actingAsSousRecetteUser(['sousrecette.create', 'sousrecette.update']);
    $unite = UniteDeMesure::factory()->create();
    $matiereA = matiereAvecPmp(10);
    $matiereB = matiereAvecPmp(5);

    $id = postJson('/api/sous-recettes', [
        'libelle' => 'Évolutive',
        'quantite_produite' => 1,
        'unite_id' => $unite->id,
        'lignes' => [
            ['matiere_premiere_id' => $matiereA->id, 'unite_id' => $unite->id, 'quantite' => 2], // 20
            ['matiere_premiere_id' => $matiereB->id, 'unite_id' => $unite->id, 'quantite' => 4], // 20
        ],
    ])->json('data.id');

    // Sync : on retire B, on garde A à 5 -> 50.
    putJson("/api/sous-recettes/{$id}", [
        'libelle' => 'Évolutive',
        'quantite_produite' => 1,
        'unite_id' => $unite->id,
        'lignes' => [
            ['matiere_premiere_id' => $matiereA->id, 'unite_id' => $unite->id, 'quantite' => 5],
        ],
    ])
        ->assertOk()
        ->assertJsonPath('data.cout_matiere', '50.00')
        ->assertJsonCount(1, 'data.lignes');

    expect(LigneSousRecette::where('sous_recette_id', $id)->count())->toBe(1);
});

// ───────────────────────── validations ─────────────────────────

it('refuse un doublon de matière dans le tableau de lignes', function () {
    actingAsSousRecetteUser(['sousrecette.create']);
    $unite = UniteDeMesure::factory()->create();
    $matiere = matiereAvecPmp(10);

    postJson('/api/sous-recettes', [
        'libelle' => 'Doublon',
        'quantite_produite' => 1,
        'unite_id' => $unite->id,
        'lignes' => [
            ['matiere_premiere_id' => $matiere->id, 'unite_id' => $unite->id, 'quantite' => 2],
            ['matiere_premiere_id' => $matiere->id, 'unite_id' => $unite->id, 'quantite' => 3],
        ],
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('lignes.0.matiere_premiere_id');
});

it('refuse une quantité de ligne nulle', function () {
    actingAsSousRecetteUser(['sousrecette.create']);
    $unite = UniteDeMesure::factory()->create();
    $matiere = matiereAvecPmp(10);

    postJson('/api/sous-recettes', [
        'libelle' => 'Quantité nulle',
        'quantite_produite' => 1,
        'unite_id' => $unite->id,
        'lignes' => [
            ['matiere_premiere_id' => $matiere->id, 'unite_id' => $unite->id, 'quantite' => 0],
        ],
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('lignes.0.quantite');
});

// ───────────────────────── lecture ─────────────────────────

it('liste et affiche les sous-recettes avec leurs lignes', function () {
    actingAsSousRecetteUser(['sousrecette.view']);
    $sousRecette = SousRecette::factory()->create();
    LigneSousRecette::factory()->count(2)->create(['sous_recette_id' => $sousRecette->id]);

    getJson('/api/sous-recettes')
        ->assertOk()
        ->assertJsonPath('data.sous_recettes.0.id', $sousRecette->id);

    getJson("/api/sous-recettes/{$sousRecette->id}")
        ->assertOk()
        ->assertJsonCount(2, 'data.lignes');
});

// ───────────────────────── suppression ─────────────────────────

it('supprime une sous-recette non utilisée (204)', function () {
    actingAsSousRecetteUser(['sousrecette.delete']);
    $sousRecette = SousRecette::factory()->create();

    deleteJson("/api/sous-recettes/{$sousRecette->id}")->assertNoContent();

    expect(SousRecette::find($sousRecette->id))->toBeNull();
});

it('expose un point d\'extension empêchant la suppression si utilisée en fiche technique', function () {
    // Le module fiche technique n'existe pas encore : le garde-fou renvoie false.
    // Le test « utilisée -> 409 » sera ajouté avec ce module (méthode déjà câblée
    // dans le destroy via SousRecetteException::utiliseeDansFicheTechnique()).
    expect(SousRecette::factory()->create()->estUtiliseeDansFicheTechnique())->toBeFalse();
});

// ───────────────────────── permissions ─────────────────────────

it('refuse l\'accès sans authentification', function () {
    getJson('/api/sous-recettes')->assertUnauthorized();
});

it('refuse la création sans la permission requise', function () {
    actingAsSousRecetteUser([]);
    $unite = UniteDeMesure::factory()->create();

    postJson('/api/sous-recettes', [
        'libelle' => 'X',
        'quantite_produite' => 1,
        'unite_id' => $unite->id,
        'lignes' => [
            ['matiere_premiere_id' => matiereAvecPmp(10)->id, 'unite_id' => $unite->id, 'quantite' => 1],
        ],
    ])->assertForbidden();
});
