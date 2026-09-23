<?php

use App\Services\Journey\JourneyClock;
use Carbon\CarbonImmutable;

it('date les visites dans le fuseau Africa/Abidjan', function (string $instant, string $timezone, string $today): void {
    $this->travelTo(CarbonImmutable::parse($instant, $timezone));

    expect(JourneyClock::timezone())->toBe('Africa/Abidjan')
        ->and(JourneyClock::today())->toBe($today)
        ->and(JourneyClock::now()->getTimezone()->getName())->toBe('Africa/Abidjan');
})->with([
    'minuit passé à Paris, pas à Abidjan' => ['2026-09-23 00:30', 'Europe/Paris', '2026-09-22'],
    'fin de journée à Abidjan' => ['2026-09-22 23:59:59', 'Africa/Abidjan', '2026-09-22'],
    'début de journée à Abidjan' => ['2026-09-23 00:00:00', 'Africa/Abidjan', '2026-09-23'],
    'soir à Los Angeles' => ['2026-09-22 18:00', 'America/Los_Angeles', '2026-09-23'],
]);
