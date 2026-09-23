<?php

use App\Models\Family;
use App\Services\Journey\RecordedVisit;

it('renvoie { visit_number, family, completed } et rien d\'autre', function (): void {
    $family = (new Family)->forceFill(['id' => 4, 'name' => 'Force', 'position' => 4, 'active' => true]);

    expect((new RecordedVisit(1, $family, true))->toArray())
        ->toBe(['visit_number' => 1, 'family' => ['id' => 4, 'name' => 'Force'], 'completed' => false])
        ->and((new RecordedVisit(3, null, false))->toArray())
        ->toBe(['visit_number' => 3, 'family' => null, 'completed' => true]);
});

it('répond 201 à une création et 200 à un rejeu', function (): void {
    expect((new RecordedVisit(2, null, true))->status())->toBe(201)
        ->and((new RecordedVisit(2, null, false))->status())->toBe(200);
});
