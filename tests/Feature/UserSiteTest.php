<?php

declare(strict_types=1);

use App\Models\Site;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('rattache un utilisateur à plusieurs sites', function () {
    $user = User::factory()->create();
    $siteA = Site::factory()->create();
    $siteB = Site::factory()->create();

    $user->sites()->attach($siteA->id);
    $user->sites()->attach($siteB->id);

    expect($user->sites)->toHaveCount(2);
});

it('expose la relation inverse site -> users', function () {
    $user = User::factory()->create();
    $site = Site::factory()->create();

    $user->sites()->attach($site->id);

    $rattache = $site->users()->sole();
    expect($rattache->is($user))->toBeTrue();
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
