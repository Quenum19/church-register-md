<?php

use App\Enums\VisitorStatus;
use App\Models\Member;
use App\Models\Visit;
use App\Models\Visitor;
use App\Services\VisitorStatusService;
use Carbon\CarbonImmutable;

beforeEach(function (): void {
    $this->statuses = app(VisitorStatusService::class);
});

it('calcule le statut selon le nombre de visites', function (): void {
    $visitor = Visitor::factory()->create();
    $start = CarbonImmutable::parse('2026-06-07');

    expect($this->statuses->refresh($visitor))->toBe(VisitorStatus::Prospect);

    Visit::factory()->first()->for($visitor)->onDate($start)->create();
    expect($this->statuses->refresh($visitor))->toBe(VisitorStatus::Prospect);

    Visit::factory()->second()->for($visitor)->onDate($start->addWeek())->create();
    expect($this->statuses->refresh($visitor))->toBe(VisitorStatus::Recurrent)
        ->and($visitor->fresh()?->status)->toBe(VisitorStatus::Recurrent);

    Visit::factory()->third()->for($visitor)->onDate($start->addWeeks(2))->create();
    expect($this->statuses->refresh($visitor))->toBe(VisitorStatus::MembrePotentiel);
});

it('passe à membre après conversion et revient au statut calculé après annulation', function (): void {
    $visitor = Visitor::factory()->membrePotentiel()->create();
    $member = Member::query()->create(['visitor_id' => $visitor->id, 'converted_at' => now()]);

    expect($this->statuses->refresh($visitor))->toBe(VisitorStatus::Membre)
        ->and($visitor->fresh()?->isMember())->toBeTrue();

    $member->delete();

    expect($this->statuses->refresh($visitor))->toBe(VisitorStatus::MembrePotentiel);
});

it('ne permet pas d\'assigner le statut en masse', function (): void {
    $visitor = Visitor::factory()->create();
    $visitor->fill(['status' => 'membre'])->save();

    expect($visitor->fresh()?->status)->toBe(VisitorStatus::Prospect);
});

it('fournit des états de factory cohérents', function (string $state, VisitorStatus $status, int $visits): void {
    $visitor = Visitor::factory()->{$state}()->create()->fresh();

    expect($visitor?->status)->toBe($status)
        ->and($visitor?->visits()->count())->toBe($visits)
        ->and($visitor?->visits()->pluck('visit_number')->all())->toBe(range(1, $visits));
})->with([
    ['prospect', VisitorStatus::Prospect, 1],
    ['recurrent', VisitorStatus::Recurrent, 2],
    ['membrePotentiel', VisitorStatus::MembrePotentiel, 3],
    ['member', VisitorStatus::Membre, 3],
]);
