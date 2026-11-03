<?php

declare(strict_types=1);

use App\Support\PeriodeResolver;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->resolver = new PeriodeResolver;
});

it('résout une journée du début à la fin du jour', function () {
    $periode = $this->resolver->resolve(['periode' => 'jour', 'date' => '2026-06-23']);

    expect($periode->type)->toBe('jour')
        ->and($periode->debut->toDateTimeString())->toBe('2026-06-23 00:00:00')
        ->and($periode->fin->toDateTimeString())->toBe('2026-06-23 23:59:59');
});

it('résout une semaine ISO (lundi → dimanche)', function () {
    // 2026-06-23 est un mardi → semaine du lundi 22 au dimanche 28.
    $periode = $this->resolver->resolve(['periode' => 'semaine', 'date' => '2026-06-23']);

    expect($periode->type)->toBe('semaine')
        ->and($periode->debut->toDateString())->toBe('2026-06-22')
        ->and($periode->debut->dayOfWeekIso)->toBe(Carbon::MONDAY)
        ->and($periode->fin->toDateString())->toBe('2026-06-28')
        ->and($periode->fin->toTimeString())->toBe('23:59:59');
});

it('résout un mois du 1er au dernier jour', function () {
    $periode = $this->resolver->resolve(['periode' => 'mois', 'date' => '2026-06-15']);

    expect($periode->type)->toBe('mois')
        ->and($periode->debut->toDateString())->toBe('2026-06-01')
        ->and($periode->fin->toDateString())->toBe('2026-06-30');
});

it('utilise le mois par défaut sans paramètre periode', function () {
    Carbon::setTestNow('2026-06-15 10:00:00');

    $periode = $this->resolver->resolve([]);

    expect($periode->type)->toBe('mois')
        ->and($periode->debut->toDateString())->toBe('2026-06-01')
        ->and($periode->fin->toDateString())->toBe('2026-06-30');

    Carbon::setTestNow();
});

it('résout une plage explicite du début au soir de la fin', function () {
    $periode = $this->resolver->resolve([
        'periode' => 'plage',
        'date_debut' => '2026-06-10',
        'date_fin' => '2026-06-20',
    ]);

    expect($periode->type)->toBe('plage')
        ->and($periode->debut->toDateTimeString())->toBe('2026-06-10 00:00:00')
        ->and($periode->fin->toDateTimeString())->toBe('2026-06-20 23:59:59');
});

/*
|--------------------------------------------------------------------------
| Période précédente
|--------------------------------------------------------------------------
|
| Piloter, c'est comparer. Le reporting ne savait que constater.
|
*/

it('recule d\'un jour, d\'une semaine et d\'un mois selon le type', function () {
    $jour = $this->resolver->precedente($this->resolver->resolve(['periode' => 'jour', 'date' => '2026-06-23']));
    $semaine = $this->resolver->precedente($this->resolver->resolve(['periode' => 'semaine', 'date' => '2026-06-23']));
    $mois = $this->resolver->precedente($this->resolver->resolve(['periode' => 'mois', 'date' => '2026-06-15']));

    expect($jour->debut->toDateString())->toBe('2026-06-22')
        ->and($semaine->debut->toDateString())->toBe('2026-06-15')
        ->and($semaine->fin->toDateString())->toBe('2026-06-21')
        ->and($mois->debut->toDateString())->toBe('2026-05-01')
        // Reculer les DEUX bornes d'un mois donnerait un 30 mai : les mois
        // n'ont pas la même longueur.
        ->and($mois->fin->toDateString())->toBe('2026-05-31');
});

it('recule une plage de sa propre longueur, bornes incluses', function () {
    // Du 10 au 20 = 11 jours → la plage d'avant va du 30 mai au 9 juin.
    $plage = $this->resolver->precedente($this->resolver->resolve([
        'periode' => 'plage', 'date_debut' => '2026-06-10', 'date_fin' => '2026-06-20',
    ]));

    expect($plage->debut->toDateString())->toBe('2026-05-30')
        ->and($plage->fin->toDateString())->toBe('2026-06-09');
});

it('conserve le type de la période comparée', function () {
    $precedente = $this->resolver->precedente($this->resolver->resolve(['periode' => 'jour', 'date' => '2026-06-23']));

    expect($precedente->type)->toBe('jour');
});
