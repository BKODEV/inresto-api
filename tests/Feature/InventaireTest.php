<?php

declare(strict_types=1);

use App\Enums\StatutInventaire;
use App\Exceptions\InventaireException;
use App\Exceptions\StockException;
use App\Models\Inventaire;
use App\Models\MatierePremiere;
use App\Models\MouvementStock;
use App\Models\Site;
use App\Models\Stock;
use App\Models\User;
use App\Services\InventaireService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;

use function Pest\Laravel\deleteJson;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->service = app(InventaireService::class);
    $this->userId = User::factory()->create()->id;
});

/**
 * @param  string[]  $permissions
 */
function actingAsInventaireUser(array $permissions = []): User
{
    $user = User::factory()->create();
    foreach ($permissions as $name) {
        Permission::findOrCreate($name, 'api');
    }
    $user->givePermissionTo($permissions);
    Sanctum::actingAs($user);

    return $user;
}

/** Crée une ligne de stock (matière, site) avec stock_courant / pmp donnés. */
function stockSur(int $matiereId, int $siteId, float $courant, float $pmp = 0): void
{
    Stock::factory()->create([
        'matiere_premiere_id' => $matiereId,
        'site_id' => $siteId,
        'stock_courant' => $courant,
        'pmp' => $pmp,
    ]);
}

// ───────────────────────── création / unicité en cours ─────────────────────────

it('crée un inventaire en cours', function () {
    $inventaire = $this->service->creer(['site_id' => Site::factory()->create()->id], $this->userId);

    expect($inventaire->statut)->toBe(StatutInventaire::EnCours);
});

it('refuse un second inventaire en cours sur le même site', function () {
    $site = Site::factory()->create();
    $this->service->creer(['site_id' => $site->id], $this->userId);

    expect(fn () => $this->service->creer(['site_id' => $site->id], $this->userId))
        ->toThrow(InventaireException::class);
});

// ───────────────────────── saisie des lignes ─────────────────────────

it('fixe quantite_theorique sur le stock courant du site à l\'ajout de ligne', function () {
    $site = Site::factory()->create();
    $matiere = MatierePremiere::factory()->create();
    stockSur($matiere->id, $site->id, 8);

    $inventaire = $this->service->creer(['site_id' => $site->id], $this->userId);
    $ligne = $this->service->ajouterOuModifierLigne(
        $inventaire,
        ['matiere_premiere_id' => $matiere->id, 'quantite_physique' => 5],
        $this->userId,
    );

    expect((float) $ligne->quantite_theorique)->toBe(8.0)
        ->and((float) $ligne->quantite_physique)->toBe(5.0);
});

it('met à jour une ligne existante en cours (une matière par inventaire)', function () {
    $site = Site::factory()->create();
    $matiere = MatierePremiere::factory()->create();
    $inventaire = $this->service->creer(['site_id' => $site->id], $this->userId);

    $this->service->ajouterOuModifierLigne($inventaire, ['matiere_premiere_id' => $matiere->id, 'quantite_physique' => 5], $this->userId);
    $this->service->ajouterOuModifierLigne($inventaire, ['matiere_premiere_id' => $matiere->id, 'quantite_physique' => 9], $this->userId);

    expect($inventaire->lignes()->count())->toBe(1)
        ->and((float) $inventaire->lignes()->first()->quantite_physique)->toBe(9.0);
});

it('supprime une ligne en cours', function () {
    $site = Site::factory()->create();
    $inventaire = $this->service->creer(['site_id' => $site->id], $this->userId);
    $ligne = $this->service->ajouterOuModifierLigne(
        $inventaire,
        ['matiere_premiere_id' => MatierePremiere::factory()->create()->id, 'quantite_physique' => 5],
        $this->userId,
    );

    $this->service->supprimerLigne($inventaire, $ligne, $this->userId);

    expect($inventaire->lignes()->count())->toBe(0);
});

it('refuse toute modification de ligne sur un inventaire clôturé (409)', function () {
    actingAsInventaireUser(['inventaire.update']);
    $site = Site::factory()->create();
    $matiere = MatierePremiere::factory()->create();
    $inventaire = $this->service->creer(['site_id' => $site->id], $this->userId);
    $this->service->ajouterOuModifierLigne($inventaire, ['matiere_premiere_id' => $matiere->id, 'quantite_physique' => 5], $this->userId);
    $this->service->cloturer($inventaire, $this->userId);

    postJson("/api/inventaires/{$inventaire->id}/lignes", [
        'matiere_premiere_id' => MatierePremiere::factory()->create()->id,
        'quantite_physique' => 3,
    ])->assertStatus(409);
});

// ───────────────────────── clôture ─────────────────────────

it('refuse la clôture d\'un inventaire sans ligne (422)', function () {
    $inventaire = $this->service->creer(['site_id' => Site::factory()->create()->id], $this->userId);

    expect(fn () => $this->service->cloturer($inventaire, $this->userId))
        ->toThrow(InventaireException::class);
});

it('met le stock courant à la quantité physique de chaque ligne à la clôture', function () {
    $site = Site::factory()->create();
    $matiere = MatierePremiere::factory()->create();
    stockSur($matiere->id, $site->id, 8, pmp: 10);

    $inventaire = $this->service->creer(['site_id' => $site->id], $this->userId);
    $this->service->ajouterOuModifierLigne($inventaire, ['matiere_premiere_id' => $matiere->id, 'quantite_physique' => 5], $this->userId);
    $this->service->cloturer($inventaire, $this->userId);

    $stock = Stock::where('matiere_premiere_id', $matiere->id)->where('site_id', $site->id)->first();
    expect((float) $stock->stock_courant)->toBe(5.0);
});

it('génère un mouvement correction_inventaire avec la bonne référence polymorphe', function () {
    $site = Site::factory()->create();
    $matiere = MatierePremiere::factory()->create();
    stockSur($matiere->id, $site->id, 8);

    $inventaire = $this->service->creer(['site_id' => $site->id], $this->userId);
    $ligne = $this->service->ajouterOuModifierLigne($inventaire, ['matiere_premiere_id' => $matiere->id, 'quantite_physique' => 5], $this->userId);
    $this->service->cloturer($inventaire, $this->userId);

    $mouvement = MouvementStock::where('reference_type', 'lignes_inventaire')
        ->where('reference_id', $ligne->id)
        ->first();

    expect($mouvement)->not->toBeNull()
        ->and($mouvement->type->value)->toBe('correction_inventaire')
        ->and($mouvement->site_id)->toBe($site->id);
});

it('fige l\'écart (physique - théorique réel lu sous verrou) sur chaque ligne', function () {
    $site = Site::factory()->create();
    $matiere = MatierePremiere::factory()->create();
    stockSur($matiere->id, $site->id, 8);

    $inventaire = $this->service->creer(['site_id' => $site->id], $this->userId);
    $ligne = $this->service->ajouterOuModifierLigne($inventaire, ['matiere_premiere_id' => $matiere->id, 'quantite_physique' => 5], $this->userId);
    $this->service->cloturer($inventaire, $this->userId);

    $ligne->refresh();
    expect((float) $ligne->ecart)->toBe(-3.0)
        ->and((float) $ligne->quantite_theorique)->toBe(8.0);
});

it('gère une matière orpheline (pas de ligne stocks) via firstOrCreate à la clôture', function () {
    $site = Site::factory()->create();
    $matiere = MatierePremiere::factory()->create(); // jamais de stock sur ce site

    $inventaire = $this->service->creer(['site_id' => $site->id], $this->userId);
    $ligne = $this->service->ajouterOuModifierLigne($inventaire, ['matiere_premiere_id' => $matiere->id, 'quantite_physique' => 5], $this->userId);
    $this->service->cloturer($inventaire, $this->userId);

    $stock = Stock::where('matiere_premiere_id', $matiere->id)->where('site_id', $site->id)->first();
    expect($stock)->not->toBeNull()
        ->and((float) $stock->stock_courant)->toBe(5.0)
        ->and((float) $ligne->refresh()->ecart)->toBe(5.0)
        ->and((float) $ligne->quantite_theorique)->toBe(0.0);
});

it('fige la valeur totale du stock à la clôture (Σ physique × pmp)', function () {
    $site = Site::factory()->create();
    $matiere = MatierePremiere::factory()->create();
    stockSur($matiere->id, $site->id, 8, pmp: 10);

    $inventaire = $this->service->creer(['site_id' => $site->id], $this->userId);
    $this->service->ajouterOuModifierLigne($inventaire, ['matiere_premiere_id' => $matiere->id, 'quantite_physique' => 5], $this->userId);
    $this->service->cloturer($inventaire, $this->userId);

    expect((float) $inventaire->fresh()->valeur_totale_stock)->toBe(50.0); // 5 × 10
});

it('annule toute la clôture si une ligne échoue (rollback atomique)', function () {
    $site = Site::factory()->create();
    $matiereOk = MatierePremiere::factory()->create();
    $matiereKo = MatierePremiere::factory()->create();
    stockSur($matiereOk->id, $site->id, 8);

    $inventaire = $this->service->creer(['site_id' => $site->id], $this->userId);
    $this->service->ajouterOuModifierLigne($inventaire, ['matiere_premiere_id' => $matiereOk->id, 'quantite_physique' => 5], $this->userId);
    $this->service->ajouterOuModifierLigne($inventaire, ['matiere_premiere_id' => $matiereKo->id, 'quantite_physique' => 5], $this->userId);

    // La matière KO devient inactive après saisie : sa correction lèvera au milieu de la boucle.
    $matiereKo->update(['statut' => 'inactif']);

    expect(fn () => $this->service->cloturer($inventaire, $this->userId))->toThrow(StockException::class);

    expect(MouvementStock::count())->toBe(0)
        ->and($inventaire->fresh()->statut)->toBe(StatutInventaire::EnCours);
});

// ───────────────────────── rapport ─────────────────────────

it('calcule la couverture pour un inventaire en cours (ecart null)', function () {
    actingAsInventaireUser(['inventaire.view', 'inventaire.create', 'inventaire.update']);
    $site = Site::factory()->create();
    $matiereA = MatierePremiere::factory()->create();
    $matiereB = MatierePremiere::factory()->create();
    stockSur($matiereA->id, $site->id, 8);
    stockSur($matiereB->id, $site->id, 3); // 2 matières présentes sur le site

    $inventaire = $this->service->creer(['site_id' => $site->id], $this->userId);
    $this->service->ajouterOuModifierLigne($inventaire, ['matiere_premiere_id' => $matiereA->id, 'quantite_physique' => 5], $this->userId);

    getJson("/api/inventaires/{$inventaire->id}/rapport")
        ->assertOk()
        ->assertJsonPath('data.nb_matieres_inventoriees', 1)
        ->assertJsonPath('data.nb_matieres_site', 2)
        ->assertJsonPath('data.taux_couverture', '50.0')
        ->assertJsonPath('data.valeur_totale_stock', null)
        ->assertJsonPath('data.lignes.0.ecart', null);
});

it('expose valeur totale et écarts figés dans le rapport après clôture', function () {
    actingAsInventaireUser(['inventaire.view']);
    $site = Site::factory()->create();
    $matiere = MatierePremiere::factory()->create();
    stockSur($matiere->id, $site->id, 8, pmp: 10);

    $inventaire = $this->service->creer(['site_id' => $site->id], $this->userId);
    $this->service->ajouterOuModifierLigne($inventaire, ['matiere_premiere_id' => $matiere->id, 'quantite_physique' => 5], $this->userId);
    $this->service->cloturer($inventaire, $this->userId);

    getJson("/api/inventaires/{$inventaire->id}/rapport")
        ->assertOk()
        ->assertJsonPath('data.valeur_totale_stock', '50.00')
        ->assertJsonPath('data.lignes.0.ecart', '-3.000');
});

// ───────────────────────── destroy ─────────────────────────

it('supprime un inventaire en cours sans lignes (200)', function () {
    actingAsInventaireUser(['inventaire.delete']);
    $inventaire = Inventaire::factory()->create();

    deleteJson("/api/inventaires/{$inventaire->id}")->assertOk();

    expect(Inventaire::find($inventaire->id))->toBeNull();
});

it('refuse de supprimer un inventaire en cours avec des lignes (409)', function () {
    actingAsInventaireUser(['inventaire.delete', 'inventaire.create', 'inventaire.update']);
    $site = Site::factory()->create();
    $inventaire = $this->service->creer(['site_id' => $site->id], $this->userId);
    $this->service->ajouterOuModifierLigne($inventaire, ['matiere_premiere_id' => MatierePremiere::factory()->create()->id, 'quantite_physique' => 5], $this->userId);

    deleteJson("/api/inventaires/{$inventaire->id}")->assertStatus(409);
});

it('refuse de supprimer un inventaire clôturé (409)', function () {
    actingAsInventaireUser(['inventaire.delete']);
    $inventaire = Inventaire::factory()->cloture()->create();

    deleteJson("/api/inventaires/{$inventaire->id}")->assertStatus(409);
});

// ───────────────────────── permissions ─────────────────────────

it('refuse l\'accès sans authentification', function () {
    getJson('/api/inventaires')->assertUnauthorized();
});

it('refuse la création sans la permission', function () {
    actingAsInventaireUser([]);

    postJson('/api/inventaires', ['site_id' => Site::factory()->create()->id])
        ->assertForbidden();
});
