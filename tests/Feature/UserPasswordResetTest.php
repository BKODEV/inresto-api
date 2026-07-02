<?php

declare(strict_types=1);

use App\Mail\ResetPasswordLinkMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;

use function Pest\Laravel\postJson;

uses(RefreshDatabase::class);

beforeEach(function () {
    seedRbac();
});

it('réinitialise le mot de passe en mode admin (nouveau mot de passe direct)', function () {
    actingAsSuperAdmin();
    $user = User::factory()->create();
    $user->assignRole('serveur');

    postJson("/api/users/{$user->id}/reset-password", ['new_password' => 'NouveauPass1'])
        ->assertOk();

    expect(Hash::check('NouveauPass1', $user->fresh()->password))->toBeTrue();
});

it('envoie un lien de réinitialisation par email (mode b)', function () {
    config(['mail.from.address' => 'no-reply@inresto.test']);
    Mail::fake();
    actingAsSuperAdmin();
    $user = User::factory()->create(['email' => 'cible@demo.local']);
    $user->assignRole('serveur');

    postJson("/api/users/{$user->id}/send-reset-link")->assertOk();

    Mail::assertSent(ResetPasswordLinkMail::class, fn ($mail) => $mail->hasTo('cible@demo.local'));
    $this->assertDatabaseHas('password_reset_tokens', ['email' => 'cible@demo.local']);
});

it('refuse l\'envoi d\'un lien si l\'utilisateur n\'a pas d\'email (422)', function () {
    config(['mail.from.address' => 'no-reply@inresto.test']);
    actingAsSuperAdmin();
    $user = User::factory()->create(['email' => null]);
    $user->assignRole('serveur');

    postJson("/api/users/{$user->id}/send-reset-link")->assertStatus(422);
});

it('échoue proprement (422) à l\'envoi d\'un lien si le SMTP n\'est pas configuré', function () {
    config(['mail.from.address' => 'hello@example.com']);
    actingAsSuperAdmin();
    $user = User::factory()->create(['email' => 'cible@demo.local']);
    $user->assignRole('serveur');

    postJson("/api/users/{$user->id}/send-reset-link")->assertStatus(422);
});

it('consomme le token public et change le mot de passe', function () {
    $user = User::factory()->create(['email' => 'reset@demo.local']);
    $token = Password::broker()->createToken($user);

    postJson('/api/auth/reset-password', [
        'email' => 'reset@demo.local',
        'token' => $token,
        'password' => 'MotDePasseFinal1',
        'password_confirmation' => 'MotDePasseFinal1',
    ])->assertOk();

    expect(Hash::check('MotDePasseFinal1', $user->fresh()->password))->toBeTrue();
});

it('refuse un token de réinitialisation invalide', function () {
    $user = User::factory()->create(['email' => 'reset2@demo.local']);

    postJson('/api/auth/reset-password', [
        'email' => 'reset2@demo.local',
        'token' => 'token-bidon',
        'password' => 'MotDePasseFinal1',
        'password_confirmation' => 'MotDePasseFinal1',
    ])->assertStatus(422);
});
