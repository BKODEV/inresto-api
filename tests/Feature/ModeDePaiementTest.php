<?php

declare(strict_types=1);

use App\Models\ModeDePaiement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;

use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;
use function Pest\Laravel\putJson;

uses(RefreshDatabase::class);

/**
 * @param  string[]  $permissions
 */
function actingAsModePaiementUser(array $permissions = []): User
{
    $user = User::factory()->create();
    foreach ($permissions as $name) {
        Permission::findOrCreate($name, 'api');
    }
    $user->givePermissionTo($permissions);
    Sanctum::actingAs($user);

    return $user;
}

// ───────────────────────── nature : en tiroir ou non ─────────────────────────

/**
 * `compte_en_caisse` décide si l'argent d'un mode entre dans le théorique de
 * fermeture. Un mode créé sans précision alimente le tiroir : le supposer hors
 * tiroir en sortirait l'argent sans que personne ne l'ait décidé.
 */
it('crée un mode qui compte en caisse par défaut', function () {
    actingAsModePaiementUser(['mode-de-paiement.create']);

    postJson('/api/parametres/modes-de-paiement', ['libelle' => 'Espèces'])
        ->assertCreated()
        ->assertJsonPath('data.compte_en_caisse', true);
});

it('accepte un mode dont l\'argent ne passe pas par le tiroir', function () {
    actingAsModePaiementUser(['mode-de-paiement.create', 'mode-de-paiement.update']);

    $reponse = postJson('/api/parametres/modes-de-paiement', [
        'libelle' => 'Virement',
        'compte_en_caisse' => false,
    ])->assertCreated()->assertJsonPath('data.compte_en_caisse', false);

    // Et se corrige : un établissement peut décider de compter autrement.
    putJson("/api/parametres/modes-de-paiement/{$reponse->json('data.id')}", [
        'compte_en_caisse' => true,
    ])->assertOk()->assertJsonPath('data.compte_en_caisse', true);
});

// ───────────────────────── ordre ─────────────────────────

it('place les nouveaux modes en fin de liste (1 puis 2) sans ordre fourni', function () {
    actingAsModePaiementUser(['mode-de-paiement.create']);

    postJson('/api/parametres/modes-de-paiement', ['libelle' => 'Espèces'])
        ->assertCreated()
        ->assertJsonPath('data.ordre', 1);

    postJson('/api/parametres/modes-de-paiement', ['libelle' => 'Virement'])
        ->assertCreated()
        ->assertJsonPath('data.ordre', 2);
});

it('décale les modes existants quand on en insère un à un ordre occupé', function () {
    actingAsModePaiementUser(['mode-de-paiement.create']);
    $existant = ModeDePaiement::factory()->create(['ordre' => 1]);

    postJson('/api/parametres/modes-de-paiement', ['libelle' => 'Wave', 'ordre' => 1])
        ->assertCreated()
        ->assertJsonPath('data.ordre', 1);

    expect($existant->fresh()->ordre)->toBe(2);
});

/**
 * L'index servait un `ModeDePaiement::all()` sans tri. PostgreSQL ne garantit
 * aucun ordre naturel — une simple mise à jour pouvait déplacer une ligne, et
 * la caisse présélectionnait le premier venu.
 */
it('trie l\'index par ordre et non par ordre physique de la table', function () {
    actingAsModePaiementUser(['mode-de-paiement.view']);
    ModeDePaiement::factory()->create(['libelle' => 'Virement', 'ordre' => 3]);
    ModeDePaiement::factory()->create(['libelle' => 'Espèces', 'ordre' => 1]);
    ModeDePaiement::factory()->create(['libelle' => 'Chèque', 'ordre' => 2]);

    getJson('/api/parametres/modes-de-paiement')
        ->assertOk()
        ->assertJsonPath('data.0.libelle', 'Espèces')
        ->assertJsonPath('data.1.libelle', 'Chèque')
        ->assertJsonPath('data.2.libelle', 'Virement');
});

// ───────────────────────── validation & permissions ─────────────────────────

it('refuse un ordre négatif', function () {
    actingAsModePaiementUser(['mode-de-paiement.create']);

    postJson('/api/parametres/modes-de-paiement', ['libelle' => 'Espèces', 'ordre' => -1])
        ->assertStatus(422)
        ->assertJsonValidationErrors('ordre');
});

it('refuse la création sans la permission', function () {
    actingAsModePaiementUser(['mode-de-paiement.view']);

    postJson('/api/parametres/modes-de-paiement', ['libelle' => 'Espèces'])
        ->assertForbidden();
});

it('refuse la lecture sans la permission', function () {
    actingAsModePaiementUser([]);

    getJson('/api/parametres/modes-de-paiement')->assertForbidden();
});
