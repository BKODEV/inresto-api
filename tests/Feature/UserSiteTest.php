<?php

declare(strict_types=1);

use App\Models\Site;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('rattache un utilisateur à plusieurs sites avec un site principal', function () {
    $user = User::factory()->create();
    $sitePrincipal = Site::factory()->create();
    $siteSecondaire = Site::factory()->create();

    $user->sites()->attach($sitePrincipal->id, ['est_principal' => true]);
    $user->sites()->attach($siteSecondaire->id, ['est_principal' => false]);

    expect($user->sites)->toHaveCount(2);

    $principal = $user->sites()->wherePivot('est_principal', true)->sole();
    expect($principal->is($sitePrincipal))->toBeTrue();
});

it('expose la relation inverse site -> users avec le pivot', function () {
    $user = User::factory()->create();
    $site = Site::factory()->create();

    $user->sites()->attach($site->id, ['est_principal' => true]);

    $rattache = $site->users()->sole();
    expect($rattache->is($user))->toBeTrue()
        ->and((bool) $rattache->pivot->est_principal)->toBeTrue();
});

it('interdit le rattachement en doublon d\'un utilisateur au même site', function () {
    $user = User::factory()->create();
    $site = Site::factory()->create();

    $user->sites()->attach($site->id);
    $user->sites()->attach($site->id); // viole l'unique (user_id, site_id)
})->throws(QueryException::class);

it('détache un utilisateur d\'un site sans toucher aux autres', function () {
    $user = User::factory()->create();
    $siteA = Site::factory()->create();
    $siteB = Site::factory()->create();
    $user->sites()->attach([$siteA->id, $siteB->id]);

    $user->sites()->detach($siteA->id);

    expect($user->sites()->pluck('sites.id')->all())->toBe([$siteB->id]);
});
