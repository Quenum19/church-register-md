<?php

require_once __DIR__.'/../Auth/helpers.php';

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Family;
use App\Models\FamilyRotation;
use App\Models\Member;
use App\Models\ReportDispatch;
use App\Models\ReportRecipient;
use App\Models\Setting;
use App\Models\User;
use App\Models\Visit;
use App\Models\Visitor;
use App\Models\VisitorNote;
use Illuminate\Database\ClassMorphViolationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

beforeEach(function (): void {
    $this->admin = userWithRole(Role::SuperAdmin, ['name' => 'Admin Principal']);
});

it('liste le journal du plus récent au plus ancien, au format du contrat', function (): void {
    $jean = User::factory()->create(['name' => 'Jean K.']);
    $old = AuditLog::factory()->create(['user_id' => $jean->id, 'action' => 'auth.login', 'ip' => '203.0.113.5', 'created_at' => Carbon::parse('2026-09-01 08:00:00')]);
    $recent = AuditLog::factory()->create([
        'user_id' => $this->admin->id,
        'action' => 'user.updated',
        'subject_type' => 'user',
        'subject_id' => $jean->id,
        'meta' => ['fields' => ['role'], 'role' => 'moderateur'],
        'created_at' => Carbon::parse('2026-09-20 10:30:00'),
    ]);

    $response = authActingAs($this->admin)->getJson('/api/admin/audit-logs')->assertOk();

    $response->assertExactJsonStructure([
        'data' => ['*' => ['id', 'action', 'user', 'subject_type', 'subject_id', 'ip', 'created_at', 'meta']],
        'meta' => ['current_page', 'last_page', 'per_page', 'total'],
    ]);
    expect(array_column($response->json('data'), 'id'))->toBe([$recent->id, $old->id]);

    expect($response->json('data.0'))->toBe([
        'id' => $recent->id,
        'action' => 'user.updated',
        'user' => ['id' => $this->admin->id, 'name' => 'Admin Principal'],
        'subject_type' => 'user',
        'subject_id' => $jean->id,
        'ip' => $recent->ip,
        'created_at' => '2026-09-20T10:30:00+00:00',
        'meta' => ['fields' => ['role'], 'role' => 'moderateur'],
    ]);
    expect($response->json('data.1'))->toMatchArray([
        'user' => ['id' => $jean->id, 'name' => 'Jean K.'],
        'subject_type' => null,
        'subject_id' => null,
        'ip' => '203.0.113.5',
        'meta' => null,
    ]);
    expect($response->json('meta'))->toBe(['current_page' => 1, 'last_page' => 1, 'per_page' => 20, 'total' => 2]);
});

it('filtre par action et par utilisateur (combinables)', function (): void {
    $jean = User::factory()->create();
    AuditLog::factory()->create(['user_id' => $jean->id, 'action' => 'auth.login']);
    $target = AuditLog::factory()->create(['user_id' => $jean->id, 'action' => 'auth.logout']);
    AuditLog::factory()->create(['user_id' => $this->admin->id, 'action' => 'auth.logout']);

    $ids = fn (array $query): array => array_column(
        authActingAs($this->admin)->getJson('/api/admin/audit-logs?'.http_build_query($query))->assertOk()->json('data'),
        'id',
    );

    expect($ids(['action' => 'auth.logout']))->toHaveCount(2)
        ->and($ids(['user_id' => $jean->id]))->toHaveCount(2)
        ->and($ids(['action' => 'auth.logout', 'user_id' => $jean->id]))->toBe([$target->id])
        ->and($ids(['action' => 'inexistante']))->toBe([]);
});

it('pagine au format du contrat et valide les paramètres', function (): void {
    AuditLog::factory()->count(5)->create(['user_id' => $this->admin->id]);

    authActingAs($this->admin)->getJson('/api/admin/audit-logs?per_page=2&page=3')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('meta', ['current_page' => 3, 'last_page' => 3, 'per_page' => 2, 'total' => 5]);

    authActingAs($this->admin)->getJson('/api/admin/audit-logs?per_page=101')->assertStatus(422)->assertJsonValidationErrors('per_page');
    authActingAs($this->admin)->getJson('/api/admin/audit-logs?page=0')->assertStatus(422)->assertJsonValidationErrors('page');
    authActingAs($this->admin)->getJson('/api/admin/audit-logs?user_id=abc')->assertStatus(422)->assertJsonValidationErrors('user_id');
    authActingAs($this->admin)->getJson('/api/admin/audit-logs?action='.str_repeat('a', 61))->assertStatus(422)->assertJsonValidationErrors('action');
});

it('renvoie last_page = 1 et une liste vide sans entrée', function (): void {
    authActingAs($this->admin)->getJson('/api/admin/audit-logs')
        ->assertOk()
        ->assertExactJson(['data' => [], 'meta' => ['current_page' => 1, 'last_page' => 1, 'per_page' => 20, 'total' => 0]]);
});

it('stocke et expose tel quel l\'alias court du contrat pour subject_type', function (string $model, string $alias): void {
    /** @var Model $subject */
    $subject = new $model;
    expect($subject->getMorphClass())->toBe($alias);

    AuditLog::factory()->create(['user_id' => null, 'subject_type' => $alias, 'subject_id' => 7]);

    authActingAs($this->admin)->getJson('/api/admin/audit-logs')
        ->assertJsonPath('data.0.subject_type', $alias)
        ->assertJsonPath('data.0.user', null);
})->with([
    [Visitor::class, 'visitor'],
    [Visit::class, 'visit'],
    [VisitorNote::class, 'note'],
    [Member::class, 'member'],
    [User::class, 'user'],
    [Family::class, 'family'],
    [FamilyRotation::class, 'rotation'],
    [ReportRecipient::class, 'recipient'],
    [ReportDispatch::class, 'report'],
]);

it('impose une morph map limitée aux alias du contrat (ni setting ni audit_log)', function (): void {
    expect(Relation::requiresMorphMap())->toBeTrue()
        ->and(array_keys(Relation::morphMap()))->toBe(
            ['visitor', 'visit', 'note', 'member', 'user', 'family', 'rotation', 'recipient', 'report'],
        );

    // « settings.updated » n'a pas de sujet : un paramètre (clé chaîne) ne peut pas en être un.
    expect(fn () => (new Setting)->getMorphClass())->toThrow(ClassMorphViolationException::class);
});

it('refuse le journal au lecteur et au modérateur (403) et aux invités (401)', function (Role $role): void {
    authActingAs(userWithRole($role))->getJson('/api/admin/audit-logs')->assertForbidden()->assertJsonPath('code', 'forbidden');
})->with([Role::Lecteur, Role::Moderateur]);

it('répond 401 sans session', function (): void {
    $this->getJson('/api/admin/audit-logs')->assertUnauthorized();
});

it('écrit une ligne pour chaque action d\'authentification et d\'administration', function (): void {
    config(['auth.timebox_duration' => 0]);
    Notification::fake();
    $jean = User::factory()->create(['email' => 'jean@exemple.org']);

    authLogin('jean@exemple.org')->assertOk();
    test()->withHeaders(AUTH_SPA_HEADERS)->postJson('/api/auth/logout')->assertNoContent();
    authResetState();

    $admin = authActingAs($this->admin);
    $created = $admin->postJson('/api/admin/users', ['name' => 'Marie', 'email' => 'marie@exemple.org', 'role' => 'lecteur'])->json('data.id');
    authActingAs($this->admin)->patchJson("/api/admin/users/{$created}", ['role' => 'moderateur'])->assertOk();
    authActingAs($this->admin)->deleteJson("/api/admin/users/{$created}")->assertNoContent();
    authActingAs($this->admin)->patchJson('/api/auth/profile', ['name' => 'Admin Renommé'])->assertOk();

    $actions = authActingAs($this->admin)->getJson('/api/admin/audit-logs')->json('data.*.action');

    expect($actions)->toEqualCanonicalizing(['auth.login', 'auth.logout', 'user.created', 'user.updated', 'user.deleted', 'user.updated'])
        ->and(AuditLog::query()->where('user_id', $jean->id)->count())->toBe(2);
});
