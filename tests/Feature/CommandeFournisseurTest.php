<?php

declare(strict_types=1);

use App\Enums\StatutCommandeFournisseur;
use App\Exceptions\CommandeFournisseurException;
use App\Models\CommandeFournisseur;
use App\Models\Fournisseur;
use App\Models\MatierePremiere;
use App\Models\UniteDeMesure;
use App\Models\User;
use App\Services\CommandeFournisseurService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;

use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;
use function Pest\Laravel\putJson;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->service = app(CommandeFournisseurService::class);
});

/**
 * Authentifie un utilisateur muni des permissions fournies.
 *
 * @param  string[]  $permissions
 */
function actingAsCommandeUser(array $permissions = []): User
{
    $user = User::factory()->create();

    foreach ($permissions as $name) {
        Permission::findOrCreate($name, 'api');
    }

    $user->givePermissionTo($permissions);
    Sanctum::actingAs($user);

    return $user;
}

/** Données d'une ligne valide (matière + unité actives). */
function ligneValide(array $surcharge = []): array
{
    return array_merge([
        'matiere_premiere_id' => MatierePremiere::factory()->create()->id,
        'unite_id' => UniteDeMesure::factory()->create()->id,
        'quantite_commandee' => 10,
        'prix_unitaire_previsionnel' => 2.5,
    ], $surcharge);
}

// ───────────────────────── enum / machine à états ─────────────────────────

it('définit les transitions autorisées de la machine à états', function () {
    expect(StatutCommandeFournisseur::Brouillon->peutTransitionnerVers(StatutCommandeFournisseur::Envoyee))->toBeTrue()
        ->and(StatutCommandeFournisseur::Brouillon->peutTransitionnerVers(StatutCommandeFournisseur::Annulee))->toBeTrue()
        ->and(StatutCommandeFournisseur::Brouillon->peutTransitionnerVers(StatutCommandeFournisseur::Cloturee))->toBeFalse()
        ->and(StatutCommandeFournisseur::Envoyee->peutTransitionnerVers(StatutCommandeFournisseur::PartiellementRecue))->toBeTrue()
        ->and(StatutCommandeFournisseur::Envoyee->peutTransitionnerVers(StatutCommandeFournisseur::Cloturee))->toBeTrue()
        ->and(StatutCommandeFournisseur::Cloturee->peutTransitionnerVers(StatutCommandeFournisseur::Annulee))->toBeFalse()
        ->and(StatutCommandeFournisseur::Brouillon->estModifiable())->toBeTrue()
        ->and(StatutCommandeFournisseur::Envoyee->estModifiable())->toBeFalse();
});

// ───────────────────────── numéro ─────────────────────────

it('génère un numéro au format CF-AAAAMM-#### incrémental par mois', function () {
    $userId = User::factory()->create()->id;
    $fournisseur = Fournisseur::factory()->create();

    $c1 = $this->service->creer(['fournisseur_id' => $fournisseur->id], [], $userId);
    $c2 = $this->service->creer(['fournisseur_id' => $fournisseur->id], [], $userId);

    $prefixe = 'CF-'.now()->format('Ym').'-';
    expect($c1->numero)->toBe($prefixe.'0001')
        ->and($c2->numero)->toBe($prefixe.'0002');
});

// ───────────────────────── création / recalcul ─────────────────────────

it('crée une commande avec ses lignes et calcule le montant prévisionnel', function () {
    $userId = User::factory()->create()->id;
    $fournisseur = Fournisseur::factory()->create();

    $commande = $this->service->creer(
        ['fournisseur_id' => $fournisseur->id],
        [
            ligneValide(['quantite_commandee' => 10, 'prix_unitaire_previsionnel' => 2.5]),  // 25
            ligneValide(['quantite_commandee' => 4, 'prix_unitaire_previsionnel' => 5]),      // 20
        ],
        $userId,
    );

    expect($commande->statut)->toBe(StatutCommandeFournisseur::Brouillon)
        ->and($commande->lignes)->toHaveCount(2)
        ->and((float) $commande->montant_previsionnel)->toBe(45.0);
});

it('recalcule le montant à l\'ajout, la mise à jour et la suppression de ligne', function () {
    $userId = User::factory()->create()->id;
    $commande = $this->service->creer(['fournisseur_id' => Fournisseur::factory()->create()->id], [], $userId);

    $ligne = $this->service->ajouterLigne($commande, ligneValide(['quantite_commandee' => 3, 'prix_unitaire_previsionnel' => 10])); // 30
    expect((float) $commande->fresh()->montant_previsionnel)->toBe(30.0);

    $this->service->mettreAJourLigne($ligne, ['quantite_commandee' => 5]); // 50
    expect((float) $commande->fresh()->montant_previsionnel)->toBe(50.0);

    $this->service->supprimerLigne($ligne);
    expect((float) $commande->fresh()->montant_previsionnel)->toBe(0.0);
});

it('refuse une ligne dont la matière première est inactive', function () {
    $userId = User::factory()->create()->id;
    $commande = $this->service->creer(['fournisseur_id' => Fournisseur::factory()->create()->id], [], $userId);
    $matiereInactive = MatierePremiere::factory()->inactif()->create();

    $this->service->ajouterLigne($commande, ligneValide(['matiere_premiere_id' => $matiereInactive->id]));
})->throws(CommandeFournisseurException::class);

// ───────────────────────── verrouillage hors brouillon ─────────────────────────

it('interdit toute modification hors du statut brouillon', function () {
    $userId = User::factory()->create()->id;
    $commande = $this->service->creer(['fournisseur_id' => Fournisseur::factory()->create()->id], [ligneValide()], $userId);
    $this->service->marquerEnvoyee($commande);

    expect(fn () => $this->service->ajouterLigne($commande, ligneValide()))
        ->toThrow(CommandeFournisseurException::class)
        ->and(fn () => $this->service->mettreAJourEntete($commande, ['notes' => 'x']))
        ->toThrow(CommandeFournisseurException::class);
});

// ───────────────────────── transitions ─────────────────────────

it('passe une commande de brouillon à envoyee', function () {
    $userId = User::factory()->create()->id;
    $commande = $this->service->creer(['fournisseur_id' => Fournisseur::factory()->create()->id], [ligneValide()], $userId);

    $this->service->marquerEnvoyee($commande);

    expect($commande->fresh()->statut)->toBe(StatutCommandeFournisseur::Envoyee);
});

it('refuse d\'envoyer une commande sans ligne', function () {
    $userId = User::factory()->create()->id;
    $commande = $this->service->creer(['fournisseur_id' => Fournisseur::factory()->create()->id], [], $userId);

    $this->service->marquerEnvoyee($commande);
})->throws(CommandeFournisseurException::class);

it('refuse une transition invalide', function () {
    $userId = User::factory()->create()->id;
    $commande = $this->service->creer(['fournisseur_id' => Fournisseur::factory()->create()->id], [ligneValide()], $userId);

    // brouillon -> cloturee n'est pas autorisé
    $this->service->marquerCloturee($commande);
})->throws(CommandeFournisseurException::class);

it('annule une commande en brouillon mais pas une commande cloturee', function () {
    $userId = User::factory()->create()->id;
    $commande = $this->service->creer(['fournisseur_id' => Fournisseur::factory()->create()->id], [ligneValide()], $userId);

    $this->service->annuler($commande);
    expect($commande->fresh()->statut)->toBe(StatutCommandeFournisseur::Annulee);

    $cloturee = CommandeFournisseur::factory()->statut(StatutCommandeFournisseur::Cloturee)->create();
    expect(fn () => $this->service->annuler($cloturee))->toThrow(CommandeFournisseurException::class);
});

// ───────────────────────── API ─────────────────────────

it('refuse l\'accès aux commandes sans authentification', function () {
    getJson('/api/commandes-fournisseur')->assertUnauthorized();
});

it('refuse l\'accès sans la permission requise', function () {
    actingAsCommandeUser();
    getJson('/api/commandes-fournisseur')->assertForbidden();
});

it('crée une commande avec lignes via l\'API', function () {
    actingAsCommandeUser(['commande-fournisseur.create']);
    $fournisseur = Fournisseur::factory()->create();

    postJson('/api/commandes-fournisseur', [
        'fournisseur_id' => $fournisseur->id,
        'lignes' => [
            ligneValide(['quantite_commandee' => 10, 'prix_unitaire_previsionnel' => 3]), // 30
        ],
    ])
        ->assertCreated()
        ->assertJsonPath('data.statut', 'brouillon')
        ->assertJsonPath('data.montant_previsionnel', '30.00')
        ->assertJsonPath('data.lignes.0.sous_total', 30);

    expect(CommandeFournisseur::count())->toBe(1);
});

it('envoie une commande via l\'API', function () {
    actingAsCommandeUser(['commande-fournisseur.create', 'commande-fournisseur.update']);
    $commande = app(CommandeFournisseurService::class)->creer(
        ['fournisseur_id' => Fournisseur::factory()->create()->id],
        [ligneValide()],
        User::factory()->create()->id,
    );

    postJson("/api/commandes-fournisseur/{$commande->id}/envoyer")
        ->assertOk()
        ->assertJsonPath('data.statut', 'envoyee');
});

it('rejette la modification d\'une commande envoyée via l\'API', function () {
    actingAsCommandeUser(['commande-fournisseur.update']);
    $commande = CommandeFournisseur::factory()->statut(StatutCommandeFournisseur::Envoyee)->create();

    putJson("/api/commandes-fournisseur/{$commande->id}", ['notes' => 'changement'])
        ->assertStatus(422)
        ->assertJsonPath('status', 'error');
});

it('valide les quantités et prix des lignes à la création', function () {
    actingAsCommandeUser(['commande-fournisseur.create']);

    postJson('/api/commandes-fournisseur', [
        'fournisseur_id' => Fournisseur::factory()->create()->id,
        'lignes' => [ligneValide(['quantite_commandee' => 0])],
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('lignes.0.quantite_commandee');
});
