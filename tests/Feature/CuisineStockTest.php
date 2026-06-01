<?php

declare(strict_types=1);

use App\Models\Budget;
use App\Models\CatalogueFournisseur;
use App\Models\CategorieMatierePremiere;
use App\Models\Fournisseur;
use App\Models\MatierePremiere;
use App\Models\Site;
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
 * Authentifie un utilisateur disposant des permissions fournies.
 *
 * @param  string[]  $permissions
 */
function actingAsUserAvec(array $permissions = []): User
{
    $user = User::factory()->create();

    foreach ($permissions as $name) {
        Permission::findOrCreate($name, 'api');
    }

    $user->givePermissionTo($permissions);

    Sanctum::actingAs($user);

    return $user;
}

// ───────────────────────── matières premières ─────────────────────────

it('refuse l\'accès aux matières premières sans authentification', function () {
    getJson('/api/matieres-premieres')->assertUnauthorized();
});

it('crée une matière première (le stock est géré par site, pas ici)', function () {
    actingAsUserAvec(['matiere-premiere.create']);

    postJson('/api/matieres-premieres', [
        'categorie_id' => CategorieMatierePremiere::factory()->create()->id,
        'unite_de_mesure_id' => UniteDeMesure::factory()->create()->id,
        'libelle' => 'Farine T55',
        // tentative d'injection de colonnes inexistantes : doit être ignorée
        'stock_courant' => 999,
        'pmp' => 999,
    ])
        ->assertCreated()
        ->assertJsonPath('data.libelle', 'Farine T55')
        ->assertJsonMissingPath('data.stock_courant')
        ->assertJsonMissingPath('data.pmp');

    $this->assertDatabaseHas('matieres_premieres', ['libelle' => 'Farine T55']);
    // Aucune ligne de stock n'est créée par le simple CRUD de la matière.
    $this->assertDatabaseCount('stocks', 0);
});

it('met à jour une matière première sans créer de stock', function () {
    actingAsUserAvec(['matiere-premiere.update']);
    $matiere = MatierePremiere::factory()->create();

    putJson("/api/matieres-premieres/{$matiere->id}", [
        'libelle' => 'Nouveau libellé',
        'stock_courant' => 500,
        'pmp' => 500,
    ])->assertOk();

    $this->assertDatabaseHas('matieres_premieres', [
        'id' => $matiere->id,
        'libelle' => 'Nouveau libellé',
    ]);
    $this->assertDatabaseCount('stocks', 0);
});

it('recherche les matières premières par libellé et filtre par catégorie et statut', function () {
    actingAsUserAvec(['matiere-premiere.view']);
    $categorie = CategorieMatierePremiere::factory()->create();
    MatierePremiere::factory()->create(['libelle' => 'Tomate', 'categorie_id' => $categorie->id]);
    MatierePremiere::factory()->create(['libelle' => 'Oignon']);
    MatierePremiere::factory()->inactif()->create(['libelle' => 'Tomate séchée']);

    getJson('/api/matieres-premieres?libelle=Tomate&statut=actif')
        ->assertOk()
        ->assertJsonCount(1, 'data.matieres_premieres')
        ->assertJsonPath('data.matieres_premieres.0.libelle', 'Tomate');

    getJson("/api/matieres-premieres?categorie_id={$categorie->id}")
        ->assertOk()
        ->assertJsonCount(1, 'data.matieres_premieres');
});

// ───────────────────────── catalogue fournisseur ─────────────────────────

it('crée une ligne de catalogue', function () {
    actingAsUserAvec(['catalogue-fournisseur.create']);

    postJson('/api/catalogue-fournisseur', [
        'fournisseur_id' => Fournisseur::factory()->create()->id,
        'matiere_premiere_id' => MatierePremiere::factory()->create()->id,
        'prix_unitaire' => 12.50,
        'unite_achat_id' => UniteDeMesure::factory()->create()->id,
        'qte_par_unite_achat' => 25,
    ])->assertCreated()
        ->assertJsonPath('data.qte_par_unite_achat', '25.000');
});

it('exige qte_par_unite_achat et le refuse s\'il est nul ou négatif', function () {
    actingAsUserAvec(['catalogue-fournisseur.create']);
    $base = [
        'fournisseur_id' => Fournisseur::factory()->create()->id,
        'matiere_premiere_id' => MatierePremiere::factory()->create()->id,
        'prix_unitaire' => 10,
        'unite_achat_id' => UniteDeMesure::factory()->create()->id,
    ];

    postJson('/api/catalogue-fournisseur', $base)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('qte_par_unite_achat');

    postJson('/api/catalogue-fournisseur', [...$base, 'qte_par_unite_achat' => 0])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('qte_par_unite_achat');
});

it('refuse un doublon fournisseur + matière première', function () {
    actingAsUserAvec(['catalogue-fournisseur.create']);
    $ligne = CatalogueFournisseur::factory()->create();

    postJson('/api/catalogue-fournisseur', [
        'fournisseur_id' => $ligne->fournisseur_id,
        'matiere_premiere_id' => $ligne->matiere_premiere_id,
        'prix_unitaire' => 5,
        'unite_achat_id' => UniteDeMesure::factory()->create()->id,
        'qte_par_unite_achat' => 1,
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('fournisseur_id');
});

it('remplace tout le catalogue d\'un fournisseur via la synchronisation', function () {
    actingAsUserAvec(['catalogue-fournisseur.create']);
    $fournisseur = Fournisseur::factory()->create();
    CatalogueFournisseur::factory()->count(2)->create(['fournisseur_id' => $fournisseur->id]);

    $m1 = MatierePremiere::factory()->create();
    $m2 = MatierePremiere::factory()->create();
    $unite = UniteDeMesure::factory()->create();

    putJson("/api/fournisseurs/{$fournisseur->id}/catalogue", [
        'lignes' => [
            ['matiere_premiere_id' => $m1->id, 'prix_unitaire' => 10, 'unite_achat_id' => $unite->id, 'qte_par_unite_achat' => 1],
            ['matiere_premiere_id' => $m2->id, 'prix_unitaire' => 20, 'unite_achat_id' => $unite->id, 'qte_par_unite_achat' => 12],
        ],
    ])->assertOk();

    expect(CatalogueFournisseur::where('fournisseur_id', $fournisseur->id)->count())->toBe(2);
    $this->assertDatabaseHas('catalogue_fournisseur', [
        'fournisseur_id' => $fournisseur->id,
        'matiere_premiere_id' => $m1->id,
    ]);
});

it('vide le catalogue quand la synchronisation reçoit un tableau vide', function () {
    actingAsUserAvec(['catalogue-fournisseur.create']);
    $fournisseur = Fournisseur::factory()->create();
    CatalogueFournisseur::factory()->count(2)->create(['fournisseur_id' => $fournisseur->id]);

    putJson("/api/fournisseurs/{$fournisseur->id}/catalogue", ['lignes' => []])->assertOk();

    expect(CatalogueFournisseur::where('fournisseur_id', $fournisseur->id)->count())->toBe(0);
});

it('refuse une matière première en doublon dans la synchronisation', function () {
    actingAsUserAvec(['catalogue-fournisseur.create']);
    $fournisseur = Fournisseur::factory()->create();
    $m = MatierePremiere::factory()->create();
    $unite = UniteDeMesure::factory()->create();

    putJson("/api/fournisseurs/{$fournisseur->id}/catalogue", [
        'lignes' => [
            ['matiere_premiere_id' => $m->id, 'prix_unitaire' => 10, 'unite_achat_id' => $unite->id, 'qte_par_unite_achat' => 1],
            ['matiere_premiere_id' => $m->id, 'prix_unitaire' => 20, 'unite_achat_id' => $unite->id, 'qte_par_unite_achat' => 1],
        ],
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('lignes.0.matiere_premiere_id');
});

// ───────────────────────── budgets ─────────────────────────

it('crée un budget pour le premier jour du mois', function () {
    actingAsUserAvec(['budget.create']);

    postJson('/api/budgets', [
        'site_id' => Site::factory()->create()->id,
        'mois' => '2026-06-01',
        'montant_alloue' => 15000,
    ])
        ->assertCreated()
        ->assertJsonPath('data.mois', '2026-06-01');
});

it('rejette un mois qui n\'est pas le premier du mois', function () {
    actingAsUserAvec(['budget.create']);

    postJson('/api/budgets', [
        'site_id' => Site::factory()->create()->id,
        'mois' => '2026-06-15',
        'montant_alloue' => 15000,
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('mois');
});

it('refuse un budget en doublon pour un site et un mois', function () {
    actingAsUserAvec(['budget.create']);
    $budget = Budget::factory()->create(['mois' => '2026-06-01']);

    postJson('/api/budgets', [
        'site_id' => $budget->site_id,
        'mois' => '2026-06-01',
        'montant_alloue' => 20000,
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('site_id');
});

it('supprime un budget', function () {
    actingAsUserAvec(['budget.delete']);
    $budget = Budget::factory()->create();

    deleteJson("/api/budgets/{$budget->id}")->assertOk();

    $this->assertDatabaseMissing('budgets', ['id' => $budget->id]);
});
