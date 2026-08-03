<?php

declare(strict_types=1);

use App\Mail\WelcomeUserMail;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

use function Pest\Laravel\deleteJson;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;
use function Pest\Laravel\putJson;

uses(RefreshDatabase::class);

beforeEach(function () {
    seedRbac();
});

/** Crée un utilisateur portant un rôle, rattaché à des sites. */
function userAvecRole(string $role, array $siteIds = []): User
{
    $user = User::factory()->create();
    $user->assignRole($role);
    $user->sites()->sync($siteIds);

    return $user;
}

it('crée un utilisateur avec le mot de passe fourni', function () {
    actingAsSuperAdmin();
    $site = Site::factory()->create();

    postJson('/api/users', [
        'nom' => 'Diallo', 'prenom' => 'Awa', 'identifiant' => 'awa',
        'password' => 'MotDePasse1', 'roles' => ['serveur'], 'sites' => [$site->id],
    ])->assertCreated();

    $user = User::where('identifiant', 'awa')->first();
    expect(Hash::check('MotDePasse1', $user->password))->toBeTrue()
        ->and($user->hasRole('serveur'))->toBeTrue();
});

it('refuse la création sans mot de passe ni email (422)', function () {
    actingAsSuperAdmin();
    $site = Site::factory()->create();

    postJson('/api/users', [
        'nom' => 'Sans', 'prenom' => 'Mail', 'identifiant' => 'sansmail',
        'sites' => [$site->id],
    ])->assertStatus(422)->assertJsonValidationErrors(['email', 'password']);
});

it('refuse la création d\'un compte sans site (422)', function () {
    actingAsSuperAdmin();

    postJson('/api/users', [
        'nom' => 'Sans', 'prenom' => 'Site', 'identifiant' => 'sanssite',
        'password' => 'Password1', 'roles' => ['serveur'],
    ])->assertStatus(422)->assertJsonValidationErrors(['sites']);

    postJson('/api/users', [
        'nom' => 'Sans', 'prenom' => 'Site', 'identifiant' => 'sanssite',
        'password' => 'Password1', 'roles' => ['serveur'], 'sites' => [],
    ])->assertStatus(422)->assertJsonValidationErrors(['sites']);

    expect(User::where('identifiant', 'sanssite')->exists())->toBeFalse();
});

it('refuse de retirer le dernier site d\'un compte existant (422)', function () {
    $site = Site::factory()->create();
    actingAsSuperAdmin([$site->id]);
    $serveur = userAvecRole('serveur', [$site->id]);

    putJson("/api/users/{$serveur->id}", ['sites' => []])
        ->assertStatus(422)->assertJsonValidationErrors(['sites']);

    expect($serveur->fresh()->sites)->toHaveCount(1);
});

it('laisse le périmètre intact quand `sites` est absent de la modification', function () {
    $site = Site::factory()->create();
    actingAsSuperAdmin([$site->id]);
    $serveur = userAvecRole('serveur', [$site->id]);

    putJson("/api/users/{$serveur->id}", ['nom' => 'Renommé'])->assertOk();

    expect($serveur->fresh()->sites->pluck('id')->all())->toBe([$site->id]);
});

it('génère un mot de passe et envoie un mail de bienvenue quand seul l\'email est fourni', function () {
    config(['mail.from.address' => 'no-reply@inresto.test']);
    Mail::fake();
    actingAsSuperAdmin();
    $site = Site::factory()->create();

    postJson('/api/users', [
        'nom' => 'Koffi', 'prenom' => 'Yao', 'identifiant' => 'yao',
        'email' => 'yao@demo.local', 'roles' => ['serveur'], 'sites' => [$site->id],
    ])->assertCreated();

    Mail::assertSent(WelcomeUserMail::class, fn ($mail) => $mail->hasTo('yao@demo.local'));
});

it('échoue proprement (422) si l\'email est requis mais le SMTP n\'est pas configuré', function () {
    config(['mail.from.address' => 'hello@example.com']); // placeholder = non configuré
    actingAsSuperAdmin();
    $site = Site::factory()->create();

    postJson('/api/users', [
        'nom' => 'Sans', 'prenom' => 'Smtp', 'identifiant' => 'sanssmtp',
        'email' => 'x@demo.local', 'roles' => ['serveur'], 'sites' => [$site->id],
    ])->assertStatus(422);

    expect(User::where('identifiant', 'sanssmtp')->exists())->toBeFalse();
});

it('anti-escalade : un gérant crée un serveur (OK) mais pas un gérant ni un super-admin', function () {
    $site = Site::factory()->create();
    actingAsRole('gerant', [$site->id]);

    postJson('/api/users', [
        'nom' => 'S', 'prenom' => 'V', 'identifiant' => 'sv1',
        'password' => 'Password1', 'roles' => ['serveur'], 'sites' => [$site->id],
    ])->assertCreated();

    postJson('/api/users', [
        'nom' => 'G', 'prenom' => 'R', 'identifiant' => 'gr1',
        'password' => 'Password1', 'roles' => ['gerant'], 'sites' => [$site->id],
    ])->assertStatus(403);

    postJson('/api/users', [
        'nom' => 'S', 'prenom' => 'A', 'identifiant' => 'sa1',
        'password' => 'Password1', 'roles' => ['super-admin'], 'sites' => [$site->id],
    ])->assertStatus(403);
});

it('anti-escalade : on ne peut assigner un rôle de niveau ≥ au sien', function () {
    $site = Site::factory()->create();
    actingAsRole('gerant', [$site->id]);
    $serveur = userAvecRole('serveur', [$site->id]);

    putJson("/api/users/{$serveur->id}", ['roles' => ['gerant']])->assertStatus(403);
});

it('périmètre : un gérant ne peut rattacher un utilisateur qu\'à ses sites', function () {
    $siteA = Site::factory()->create();
    $siteB = Site::factory()->create();
    actingAsRole('gerant', [$siteA->id]);

    postJson('/api/users', [
        'nom' => 'Hors', 'prenom' => 'Site', 'identifiant' => 'hors1',
        'password' => 'Password1', 'roles' => ['serveur'], 'sites' => [$siteB->id],
    ])->assertStatus(403);
});

it('périmètre : un gérant ne voit pas un utilisateur hors de ses sites', function () {
    $siteA = Site::factory()->create();
    $siteB = Site::factory()->create();
    actingAsRole('gerant', [$siteA->id]);
    $horsPerimetre = userAvecRole('serveur', [$siteB->id]);

    getJson("/api/users/{$horsPerimetre->id}")->assertForbidden();

    $ids = collect(getJson('/api/users')->assertOk()->json('data.users'))->pluck('id');
    expect($ids)->not->toContain($horsPerimetre->id);
});

it('hiérarchie : un gérant ne peut pas supprimer un autre gérant', function () {
    $site = Site::factory()->create();
    actingAsRole('gerant', [$site->id]);
    $autreGerant = userAvecRole('gerant', [$site->id]);

    deleteJson("/api/users/{$autreGerant->id}")->assertStatus(403);
});

it('super-admin court-circuite hiérarchie et périmètre', function () {
    $siteA = Site::factory()->create();
    $siteB = Site::factory()->create();
    actingAsSuperAdmin([$siteA->id]);
    $autreGerant = userAvecRole('gerant', [$siteB->id]);

    getJson("/api/users/{$autreGerant->id}")->assertOk();
    deleteJson("/api/users/{$autreGerant->id}")->assertOk();
});

it('supprime un utilisateur en soft delete (historique préservé)', function () {
    actingAsSuperAdmin();
    $user = userAvecRole('serveur');

    deleteJson("/api/users/{$user->id}")->assertOk();

    $this->assertSoftDeleted('users', ['id' => $user->id]);
});

it('active et désactive un utilisateur', function () {
    actingAsSuperAdmin();
    $user = userAvecRole('serveur');

    postJson("/api/users/{$user->id}/desactiver")->assertOk();
    expect($user->fresh()->statut)->toBe('inactif');

    postJson("/api/users/{$user->id}/activer")->assertOk();
    expect($user->fresh()->statut)->toBe('actif');
});

it('empêche la suppression du dernier super-admin actif (409)', function () {
    $superAdmin = actingAsSuperAdmin();

    deleteJson("/api/users/{$superAdmin->id}")->assertStatus(409);
    postJson("/api/users/{$superAdmin->id}/desactiver")->assertStatus(409);
});

it('autorise la suppression d\'un super-admin s\'il en reste un autre actif', function () {
    $superAdmin = actingAsSuperAdmin();
    $autre = userAvecRole('super-admin');

    deleteJson("/api/users/{$autre->id}")->assertOk();
    $this->assertSoftDeleted('users', ['id' => $autre->id]);
});

it('refuse l\'accès aux utilisateurs sans la permission utilisateur.view', function () {
    actingAsRole('serveur');

    getJson('/api/users')->assertForbidden();
});
