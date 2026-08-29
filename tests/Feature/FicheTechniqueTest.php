<?php

declare(strict_types=1);

use App\Enums\StatutFicheTechnique;
use App\Models\CatalogueFournisseur;
use App\Models\FicheTechnique;
use App\Models\Fournisseur;
use App\Models\LigneFicheTechniqueSousRecette;
use App\Models\MatierePremiere;
use App\Models\Produit;
use App\Models\Site;
use App\Models\SousRecette;
use App\Models\Stock;
use App\Models\UniteDeMesure;
use App\Models\User;
use App\Services\FicheTechniqueService;
use App\Services\SousRecetteService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;

use function Pest\Laravel\deleteJson;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;
use function Pest\Laravel\putJson;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->service = app(FicheTechniqueService::class);
    $this->userId = User::factory()->create()->id;
});

/**
 * @param  string[]  $permissions
 */
function actingAsFicheUser(array $permissions = []): User
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
 * Matière avec un coût unitaire catalogue donné (prix_unitaire / qte_par_unite_achat).
 */
function matiereAvecCoutCatalogue(float $coutUnitaire): MatierePremiere
{
    $matiere = MatierePremiere::factory()->create();

    CatalogueFournisseur::factory()->create([
        'fournisseur_id' => Fournisseur::factory()->create()->id,
        'matiere_premiere_id' => $matiere->id,
        'prix_unitaire' => $coutUnitaire,
        'qte_par_unite_achat' => 1,
    ]);

    return $matiere;
}

// ───────────────────────── création / coût ─────────────────────────

it('crée une fiche v1 active avec coût matière calculé', function () {
    actingAsFicheUser(['fichetechnique.create']);
    $produit = Produit::factory()->create();
    $unite = UniteDeMesure::factory()->create();
    $matiere = matiereAvecCoutCatalogue(5); // 5 × 3 = 15

    postJson('/api/fiches-techniques', [
        'produit_id' => $produit->id,
        'lignes_matieres' => [
            ['matiere_premiere_id' => $matiere->id, 'unite_id' => $unite->id, 'quantite' => 3],
        ],
    ])
        ->assertCreated()
        ->assertJsonPath('data.version', 1)
        ->assertJsonPath('data.statut', 'active')
        ->assertJsonPath('data.cout_matiere', '15.00');
});

it('estampe l\'unité de stockage de la matière, en ignorant l\'unité envoyée', function () {
    actingAsFicheUser(['fichetechnique.create']);
    $produit = Produit::factory()->create();
    $uniteStockage = UniteDeMesure::factory()->create(['symbole' => 'kg']);
    $matiere = MatierePremiere::factory()->create(['unite_de_mesure_id' => $uniteStockage->id]);
    $uniteIncoherente = UniteDeMesure::factory()->create(['symbole' => 'L']);

    postJson('/api/fiches-techniques', [
        'produit_id' => $produit->id,
        'lignes_matieres' => [
            // Le client tente une unité incohérente (litres pour une matière en kg).
            ['matiere_premiere_id' => $matiere->id, 'unite_id' => $uniteIncoherente->id, 'quantite' => 2],
        ],
    ])->assertCreated();

    // La ligne est enregistrée avec l'unité de stockage, pas celle envoyée.
    $this->assertDatabaseHas('lignes_fiche_technique', [
        'matiere_premiere_id' => $matiere->id,
        'unite_id' => $uniteStockage->id,
    ]);
    $this->assertDatabaseMissing('lignes_fiche_technique', [
        'matiere_premiere_id' => $matiere->id,
        'unite_id' => $uniteIncoherente->id,
    ]);
});

it('crée la fiche sans qu\'aucune unité ne soit fournie dans le payload', function () {
    actingAsFicheUser(['fichetechnique.create']);
    $produit = Produit::factory()->create();
    $matiere = matiereAvecCoutCatalogue(5);

    postJson('/api/fiches-techniques', [
        'produit_id' => $produit->id,
        'lignes_matieres' => [
            ['matiere_premiere_id' => $matiere->id, 'quantite' => 3],
        ],
    ])
        ->assertCreated()
        ->assertJsonPath('data.cout_matiere', '15.00');

    $this->assertDatabaseHas('lignes_fiche_technique', [
        'matiere_premiere_id' => $matiere->id,
        'unite_id' => $matiere->unite_de_mesure_id,
    ]);
});

it('garde le meilleur prix catalogue (MIN) parmi plusieurs fournisseurs', function () {
    actingAsFicheUser(['fichetechnique.create']);
    $produit = Produit::factory()->create();
    $unite = UniteDeMesure::factory()->create();
    $matiere = MatierePremiere::factory()->create();
    CatalogueFournisseur::factory()->create([
        'fournisseur_id' => Fournisseur::factory()->create()->id,
        'matiere_premiere_id' => $matiere->id, 'prix_unitaire' => 10, 'qte_par_unite_achat' => 2, // 5
    ]);
    CatalogueFournisseur::factory()->create([
        'fournisseur_id' => Fournisseur::factory()->create()->id,
        'matiere_premiere_id' => $matiere->id, 'prix_unitaire' => 12, 'qte_par_unite_achat' => 4, // 3 (meilleur)
    ]);

    postJson('/api/fiches-techniques', [
        'produit_id' => $produit->id,
        'lignes_matieres' => [
            ['matiere_premiere_id' => $matiere->id, 'unite_id' => $unite->id, 'quantite' => 2], // 2 × 3 = 6
        ],
    ])
        ->assertCreated()
        ->assertJsonPath('data.cout_matiere', '6.00');
});

it('met le coût unitaire à 0 pour une matière hors catalogue (pas d\'erreur)', function () {
    actingAsFicheUser(['fichetechnique.create']);
    $produit = Produit::factory()->create();
    $unite = UniteDeMesure::factory()->create();
    $matiere = MatierePremiere::factory()->create(); // aucun catalogue

    postJson('/api/fiches-techniques', [
        'produit_id' => $produit->id,
        'lignes_matieres' => [
            ['matiere_premiere_id' => $matiere->id, 'unite_id' => $unite->id, 'quantite' => 4],
        ],
    ])
        ->assertCreated()
        ->assertJsonPath('data.cout_matiere', '0.00');
});

it('agrège lignes matières et lignes sous-recettes dans le coût', function () {
    actingAsFicheUser(['fichetechnique.create']);
    $produit = Produit::factory()->create();
    $unite = UniteDeMesure::factory()->create();
    $matiere = matiereAvecCoutCatalogue(5); // 2 × 5 = 10

    // Sous-recette : cout_matiere 100 pour 10 produits -> 10/unité ; × 3 = 30.
    $sousRecette = SousRecette::factory()->create(['quantite_produite' => 10]);
    $sousRecette->cout_matiere = 100;
    $sousRecette->save();

    postJson('/api/fiches-techniques', [
        'produit_id' => $produit->id,
        'lignes_matieres' => [
            ['matiere_premiere_id' => $matiere->id, 'unite_id' => $unite->id, 'quantite' => 2],
        ],
        'lignes_sous_recettes' => [
            ['sous_recette_id' => $sousRecette->id, 'quantite' => 3],
        ],
    ])
        ->assertCreated()
        ->assertJsonPath('data.cout_matiere', '40.00');
});

// ───────────────────────── versioning ─────────────────────────

it('crée une nouvelle version active et archive l\'ancienne', function () {
    actingAsFicheUser(['fichetechnique.create', 'fichetechnique.update']);
    $produit = Produit::factory()->create();
    $unite = UniteDeMesure::factory()->create();
    $matiere = matiereAvecCoutCatalogue(5);

    $payload = fn (float $qte) => [
        'produit_id' => $produit->id,
        'lignes_matieres' => [
            ['matiere_premiere_id' => $matiere->id, 'unite_id' => $unite->id, 'quantite' => $qte],
        ],
    ];

    $v1 = postJson('/api/fiches-techniques', $payload(2))->json('data.id');
    postJson('/api/fiches-techniques', $payload(4))->assertCreated()->assertJsonPath('data.version', 2);

    expect(FicheTechnique::find($v1)->statut)->toBe(StatutFicheTechnique::Archivee)
        ->and(FicheTechnique::where('produit_id', $produit->id)->where('statut', 'active')->count())->toBe(1)
        ->and(FicheTechnique::where('produit_id', $produit->id)->count())->toBe(2);
});

it('annule toute la transaction si une erreur survient en cours de versioning', function () {
    $produit = Produit::factory()->create();
    $unite = UniteDeMesure::factory()->create();
    $matiere = matiereAvecCoutCatalogue(5);

    $v1 = $this->service->creer([
        'produit_id' => $produit->id,
        'lignes_matieres' => [['matiere_premiere_id' => $matiere->id, 'unite_id' => $unite->id, 'quantite' => 2]],
    ], $this->userId);

    // Doublon de matière -> violation d'unicité au 2e insert, en pleine transaction.
    expect(fn () => $this->service->modifier($v1, [
        'produit_id' => $produit->id,
        'lignes_matieres' => [
            ['matiere_premiere_id' => $matiere->id, 'unite_id' => $unite->id, 'quantite' => 1],
            ['matiere_premiere_id' => $matiere->id, 'unite_id' => $unite->id, 'quantite' => 2],
        ],
    ], $this->userId))->toThrow(QueryException::class);

    // Rollback : v1 reste active, aucune v2 créée.
    expect($v1->fresh()->statut)->toBe(StatutFicheTechnique::Active)
        ->and(FicheTechnique::where('produit_id', $produit->id)->count())->toBe(1);
});

// ───────────────────────── cascade depuis sous-recette ─────────────────────────

it('recalcule la fiche active en cascade quand sa sous-recette change', function () {
    $sousRecetteService = app(SousRecetteService::class);
    $unite = UniteDeMesure::factory()->create();

    // Matière à PMP 10 (pour le coût de la sous-recette).
    $matiere = MatierePremiere::factory()->create();
    Stock::factory()->create(['matiere_premiere_id' => $matiere->id, 'site_id' => Site::factory()->create()->id, 'pmp' => 10]);

    // Sous-recette : 2 × 10 = 20, quantite_produite 1 -> 20/unité.
    $sousRecette = $sousRecetteService->creer([
        'libelle' => 'Base', 'quantite_produite' => 1, 'unite_id' => $unite->id,
        'lignes' => [['matiere_premiere_id' => $matiere->id, 'unite_id' => $unite->id, 'quantite' => 2]],
    ], $this->userId);

    // Fiche active référençant la sous-recette : 20 × 3 = 60.
    $fiche = $this->service->creer([
        'produit_id' => Produit::factory()->create()->id,
        'lignes_sous_recettes' => [['sous_recette_id' => $sousRecette->id, 'quantite' => 3]],
    ], $this->userId);
    expect((float) $fiche->cout_matiere)->toBe(60.0);

    // On modifie la sous-recette (quantité 5 -> coût 50) : cascade vers la fiche.
    $sousRecetteService->modifier($sousRecette, [
        'libelle' => 'Base', 'quantite_produite' => 1, 'unite_id' => $unite->id,
        'lignes' => [['matiere_premiere_id' => $matiere->id, 'unite_id' => $unite->id, 'quantite' => 5]],
    ], $this->userId);

    // Fiche recalculée : 50 × 3 = 150.
    expect((float) $fiche->fresh()->cout_matiere)->toBe(150.0);
});

// ───────────────────────── point d'extension sous-recette ─────────────────────────

it('estUtiliseeDansFicheTechnique renvoie true quand une fiche active la référence', function () {
    $sousRecette = SousRecette::factory()->create();
    expect($sousRecette->estUtiliseeDansFicheTechnique())->toBeFalse();

    $fiche = FicheTechnique::factory()->create();
    LigneFicheTechniqueSousRecette::factory()->create([
        'fiche_technique_id' => $fiche->id,
        'sous_recette_id' => $sousRecette->id,
    ]);

    expect($sousRecette->estUtiliseeDansFicheTechnique())->toBeTrue();
});

it('bloque la suppression d\'une sous-recette utilisée dans une fiche active (409)', function () {
    actingAsFicheUser(['sousrecette.delete']);
    $sousRecette = SousRecette::factory()->create();
    $fiche = FicheTechnique::factory()->create();
    LigneFicheTechniqueSousRecette::factory()->create([
        'fiche_technique_id' => $fiche->id,
        'sous_recette_id' => $sousRecette->id,
    ]);

    deleteJson("/api/sous-recettes/{$sousRecette->id}")->assertStatus(409);
});

// ───────────────────────── suppression ─────────────────────────

it('supprime une fiche active non référencée (200)', function () {
    // Contrainte projet : destroy renvoie 200 via ApiResponse::deleted (pas 204).
    actingAsFicheUser(['fichetechnique.delete']);
    $fiche = FicheTechnique::factory()->create();

    deleteJson("/api/fiches-techniques/{$fiche->id}")->assertOk();

    expect(FicheTechnique::find($fiche->id))->toBeNull();
});

it('refuse de supprimer une fiche archivée (409)', function () {
    actingAsFicheUser(['fichetechnique.delete']);
    $fiche = FicheTechnique::factory()->archivee()->create();

    deleteJson("/api/fiches-techniques/{$fiche->id}")->assertStatus(409);

    expect(FicheTechnique::find($fiche->id))->not->toBeNull();
});

it('expose un point d\'extension carte active empêchant la suppression', function () {
    // carte_produit n'existe pas encore : le garde-fou renvoie false. Le test
    // « référencée -> 409 » sera branché au Module Salle.
    expect(FicheTechnique::factory()->create()->estReferenceeDansCarteActive())->toBeFalse();
});

// ───────────────────────── lecture ─────────────────────────

it('liste les fiches actives avec filtre ?produit_id', function () {
    actingAsFicheUser(['fichetechnique.view']);
    $produitA = Produit::factory()->create();
    $produitB = Produit::factory()->create();
    FicheTechnique::factory()->create(['produit_id' => $produitA->id]);
    FicheTechnique::factory()->create(['produit_id' => $produitB->id]);
    FicheTechnique::factory()->archivee()->create(['produit_id' => $produitA->id]);

    getJson("/api/fiches-techniques?produit_id={$produitA->id}")
        ->assertOk()
        ->assertJsonCount(1, 'data.fiches_techniques')
        ->assertJsonPath('data.fiches_techniques.0.produit_id', $produitA->id);
});

it('retourne l\'historique complet d\'un produit (toutes versions)', function () {
    actingAsFicheUser(['fichetechnique.view']);
    $produit = Produit::factory()->create();
    FicheTechnique::factory()->version(1)->archivee()->create(['produit_id' => $produit->id]);
    FicheTechnique::factory()->version(2)->create(['produit_id' => $produit->id]);

    getJson("/api/fiches-techniques/{$produit->id}/historique")
        ->assertOk()
        ->assertJsonCount(2, 'data.fiches_techniques')
        ->assertJsonPath('data.fiches_techniques.0.version', 2); // tri version desc
});

// ───────────────────────── validations / permissions ─────────────────────────

it('refuse une fiche sans aucune ligne', function () {
    actingAsFicheUser(['fichetechnique.create']);

    postJson('/api/fiches-techniques', [
        'produit_id' => Produit::factory()->create()->id,
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('lignes_matieres');
});

it('refuse un doublon de matière dans les lignes', function () {
    actingAsFicheUser(['fichetechnique.create']);
    $unite = UniteDeMesure::factory()->create();
    $matiere = matiereAvecCoutCatalogue(5);

    postJson('/api/fiches-techniques', [
        'produit_id' => Produit::factory()->create()->id,
        'lignes_matieres' => [
            ['matiere_premiere_id' => $matiere->id, 'unite_id' => $unite->id, 'quantite' => 1],
            ['matiere_premiere_id' => $matiere->id, 'unite_id' => $unite->id, 'quantite' => 2],
        ],
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('lignes_matieres.0.matiere_premiere_id');
});

it('refuse l\'accès sans authentification', function () {
    getJson('/api/fiches-techniques')->assertUnauthorized();
});

it('refuse la création sans la permission', function () {
    actingAsFicheUser([]);

    postJson('/api/fiches-techniques', [
        'produit_id' => Produit::factory()->create()->id,
        'lignes_matieres' => [
            ['matiere_premiere_id' => matiereAvecCoutCatalogue(5)->id, 'unite_id' => UniteDeMesure::factory()->create()->id, 'quantite' => 1],
        ],
    ])->assertForbidden();
});

// ───────────────────────── méthodologie ─────────────────────────

/**
 * Une fiche qui liste les matières sans dire quoi en faire n'est pas
 * exécutable : un intérimaire tient la liste des courses, pas la recette.
 */
it('enregistre étapes, matériel et points critiques', function () {
    actingAsFicheUser(['fichetechnique.create']);
    $produit = Produit::factory()->create();
    $matiere = matiereAvecCoutCatalogue(5);

    postJson('/api/fiches-techniques', [
        'produit_id' => $produit->id,
        'lignes_matieres' => [['matiere_premiere_id' => $matiere->id, 'quantite' => 3]],
        'etapes' => [
            ['description' => 'Désosser le poulet.', 'duree_minutes' => 10],
            ['description' => 'Mariner au piment vert.', 'duree_minutes' => 120],
            ['description' => 'Dresser avec l\'attiéké.'],
        ],
        'materiels' => ['Braisière', 'Sonde'],
        'points_critiques' => [
            ['type' => 'cuisson', 'description' => 'Cuisson à cœur : 74 °C.'],
            ['type' => 'allergene', 'description' => 'Arachide dans la marinade.'],
        ],
    ])
        ->assertCreated()
        ->assertJsonCount(3, 'data.etapes')
        ->assertJsonCount(2, 'data.materiels')
        ->assertJsonCount(2, 'data.points_critiques')
        ->assertJsonPath('data.points_critiques.0.type_libelle', 'Cuisson')
        ->assertJsonPath('data.materiels.0.libelle', 'Braisière');
});

/**
 * L'ordre vient de la POSITION dans le tableau, jamais d'un champ envoyé par le
 * client : deux étapes au même rang rendraient la fiche imprimée non
 * déterministe, et un intérimaire ferait mariner après avoir servi.
 */
it('numérote les étapes dans l\'ordre reçu', function () {
    actingAsFicheUser(['fichetechnique.create']);
    $produit = Produit::factory()->create();
    $matiere = matiereAvecCoutCatalogue(5);

    $reponse = postJson('/api/fiches-techniques', [
        'produit_id' => $produit->id,
        'lignes_matieres' => [['matiere_premiere_id' => $matiere->id, 'quantite' => 1]],
        'etapes' => [
            ['description' => 'Première'],
            ['description' => 'Deuxième'],
            ['description' => 'Troisième'],
        ],
    ])->assertCreated();

    expect($reponse->json('data.etapes.*.ordre'))->toBe([1, 2, 3])
        ->and($reponse->json('data.etapes.*.description'))
        ->toBe(['Première', 'Deuxième', 'Troisième']);
});

/**
 * La durée cumulée ne compte que les étapes chiffrées. `null` quand aucune ne
 * l'est : annoncer « 0 min » sur une recette non chiffrée serait un mensonge.
 */
it('cumule les durées, et ne dit rien quand aucune n\'est chiffrée', function () {
    actingAsFicheUser(['fichetechnique.create']);
    $produit = Produit::factory()->create();
    $matiere = matiereAvecCoutCatalogue(5);

    postJson('/api/fiches-techniques', [
        'produit_id' => $produit->id,
        'lignes_matieres' => [['matiere_premiere_id' => $matiere->id, 'quantite' => 1]],
        'etapes' => [
            ['description' => 'Cuire', 'duree_minutes' => 25],
            ['description' => 'Reposer', 'duree_minutes' => 5],
            ['description' => 'Dresser'],
        ],
    ])
        ->assertCreated()
        ->assertJsonPath('data.duree_totale_minutes', 30);

    postJson('/api/fiches-techniques', [
        'produit_id' => Produit::factory()->create()->id,
        'lignes_matieres' => [['matiere_premiere_id' => $matiere->id, 'quantite' => 1]],
        'etapes' => [['description' => 'Assembler']],
    ])
        ->assertCreated()
        ->assertJsonPath('data.duree_totale_minutes', null);
});

/**
 * La méthodologie appartient à SA version. Sans recopie, la nouvelle version
 * naîtrait sans mode opératoire ; sans isolation, corriger une étape
 * réécrirait la recette de plats déjà vendus (cf. versioning).
 */
it('recopie la méthodologie sur la nouvelle version, sans toucher à l\'archivée', function () {
    actingAsFicheUser(['fichetechnique.create', 'fichetechnique.update']);
    $produit = Produit::factory()->create();
    $matiere = matiereAvecCoutCatalogue(5);

    $v1 = postJson('/api/fiches-techniques', [
        'produit_id' => $produit->id,
        'lignes_matieres' => [['matiere_premiere_id' => $matiere->id, 'quantite' => 1]],
        'etapes' => [['description' => 'Étape v1', 'duree_minutes' => 10]],
        'points_critiques' => [['type' => 'cuisson', 'description' => '74 °C']],
    ])->assertCreated()->json('data.id');

    putJson("/api/fiches-techniques/{$v1}", [
        'produit_id' => $produit->id,
        'lignes_matieres' => [['matiere_premiere_id' => $matiere->id, 'quantite' => 2]],
        'etapes' => [
            ['description' => 'Étape v2', 'duree_minutes' => 15],
            ['description' => 'Nouvelle étape'],
        ],
        'points_critiques' => [['type' => 'allergene', 'description' => 'Arachide']],
    ])
        ->assertOk()
        ->assertJsonPath('data.version', 2)
        ->assertJsonCount(2, 'data.etapes')
        ->assertJsonPath('data.etapes.0.description', 'Étape v2')
        ->assertJsonPath('data.points_critiques.0.type', 'allergene');

    $archivee = FicheTechnique::find($v1);
    expect($archivee->statut)->toBe(StatutFicheTechnique::Archivee)
        ->and($archivee->etapes)->toHaveCount(1)
        ->and($archivee->etapes->first()->description)->toBe('Étape v1')
        ->and($archivee->pointsCritiques->first()->type->value)->toBe('cuisson');
});

it('refuse un type de point critique inconnu (422)', function () {
    // Liste fermée : un contrôle qui ne distingue pas un allergène d'une
    // température de cuisson ne contrôle rien.
    actingAsFicheUser(['fichetechnique.create']);
    $matiere = matiereAvecCoutCatalogue(5);

    postJson('/api/fiches-techniques', [
        'produit_id' => Produit::factory()->create()->id,
        'lignes_matieres' => [['matiere_premiere_id' => $matiere->id, 'quantite' => 1]],
        'points_critiques' => [['type' => 'important', 'description' => 'Bien surveiller']],
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('points_critiques.0.type');
});

it('refuse deux fois le même matériel (422)', function () {
    actingAsFicheUser(['fichetechnique.create']);
    $matiere = matiereAvecCoutCatalogue(5);

    postJson('/api/fiches-techniques', [
        'produit_id' => Produit::factory()->create()->id,
        'lignes_matieres' => [['matiere_premiere_id' => $matiere->id, 'quantite' => 1]],
        'materiels' => ['Braisière', 'Braisière'],
    ])->assertStatus(422);
});

it('reste créable sans aucune méthodologie', function () {
    // Les fiches existantes n'en ont pas : la rendre obligatoire les casserait
    // toutes, et un plat d'assemblage n'a pas de mode opératoire.
    actingAsFicheUser(['fichetechnique.create']);
    $matiere = matiereAvecCoutCatalogue(5);

    postJson('/api/fiches-techniques', [
        'produit_id' => Produit::factory()->create()->id,
        'lignes_matieres' => [['matiere_premiere_id' => $matiere->id, 'quantite' => 1]],
    ])
        ->assertCreated()
        ->assertJsonCount(0, 'data.etapes')
        ->assertJsonPath('data.duree_totale_minutes', null);
});
