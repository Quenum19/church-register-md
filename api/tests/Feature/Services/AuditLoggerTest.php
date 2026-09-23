<?php

use App\Models\AuditLog;
use App\Models\User;
use App\Models\Visitor;
use App\Services\AuditLogger;
use App\Support\RouteGroups;

it('journalise l\'utilisateur et l\'IP de la requête courante', function (): void {
    $user = User::factory()->superAdmin()->create();
    $visitor = Visitor::factory()->create();

    testRoute(RouteGroups::ADMIN_PREFIX, RouteGroups::ADMIN_MIDDLEWARE, 'POST', '__audit/{visitor}', function (Visitor $visitor, AuditLogger $audit) {
        $audit->log('visitor.updated', $visitor, ['fields' => ['commune']]);

        return response()->noContent();
    });

    $this->actingAs($user)
        ->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])
        ->postJson("/api/admin/__audit/{$visitor->id}")
        ->assertNoContent();

    $log = AuditLog::query()->sole();

    expect($log->action)->toBe('visitor.updated')
        ->and($log->user_id)->toBe($user->id)
        ->and($log->ip)->toBe('203.0.113.7')
        ->and($log->subject_type)->toBe('visitor')
        ->and($log->subject_id)->toBe($visitor->id)
        ->and($log->meta)->toBe(['fields' => ['commune']])
        ->and($log->created_at)->not->toBeNull();
});

it('accepte un utilisateur explicite et un sujet absent', function (): void {
    $user = User::factory()->create();

    $log = app(AuditLogger::class)->log('auth.login', null, [], $user);

    expect($log->user_id)->toBe($user->id)
        ->and($log->subject_type)->toBeNull()
        ->and($log->meta)->toBeNull();
});
