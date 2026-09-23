<?php

use App\Models\User;
use App\Notifications\Auth\AccountLockedNotification;
use App\Notifications\Auth\InvitationNotification;
use App\Notifications\Auth\ResetPasswordNotification;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Jetons en file d'attente (revue de sécurité, point 2)
|--------------------------------------------------------------------------
|
| Les notifications d'invitation et de réinitialisation sont mises en file (QUEUE_CONNECTION
| = database, worker lancé chaque minute). Leur charge utile sérialisée dort donc dans la table
| `jobs` — et y reste des jours en cas d'échec, dans `failed_jobs`. Un jeton valable 60 min
| (réinitialisation) ou 48 h (invitation) n'a rien à y faire en clair : ShouldBeEncrypted.
|
| Note : `afterCommit` est neutralisé dans ces tests ; la transaction de RefreshDatabase n'étant
| jamais validée, le travail ne serait sinon jamais poussé dans `jobs`.
|
*/

/**
 * Pousse la notification dans la file « database » et renvoie la charge utile stockée.
 *
 * @return array{payload: string, command: string, encrypted: bool}
 */
function queuedPayload(User $user, object $notification): array
{
    $job = new SendQueuedNotifications($user, $notification, ['mail']);
    $job->afterCommit = false;

    Queue::connection('database')->push($job);

    $payload = (string) DB::table('jobs')->orderByDesc('id')->value('payload');
    $data = json_decode($payload, true);

    return [
        'payload' => $payload,
        'command' => is_array($data) ? (string) $data['data']['command'] : '',
        'encrypted' => (bool) $job->shouldBeEncrypted,
    ];
}

it('ne laisse jamais le jeton en clair dans la table jobs', function (string $class): void {
    $user = User::factory()->create();
    $token = 'jeton-de-test-'.Str::random(40);

    expect(new $class($token))->toBeInstanceOf(ShouldBeEncrypted::class);

    $queued = queuedPayload($user, new $class($token));

    expect($queued['encrypted'])->toBeTrue()
        ->and($queued['payload'])->not->toContain($token)
        // Chiffrement réellement actif : ce n'est pas un objet PHP sérialisé en clair,
        // et le déchiffrement avec APP_KEY redonne bien la charge utile d'origine.
        ->and($queued['command'])->not->toStartWith('O:')
        ->and(Crypt::decrypt($queued['command']))->toContain($token);
})->with([
    'réinitialisation' => [ResetPasswordNotification::class],
    'invitation' => [InvitationNotification::class],
]);

it('détecterait une régression : sans ShouldBeEncrypted, la charge utile est lisible', function (): void {
    $queued = queuedPayload(
        User::factory()->create(),
        new AccountLockedNotification(now()->addMinutes(15), '203.0.x.x'),
    );

    // Cette notification ne transporte aucun secret : elle reste volontairement en clair,
    // ce qui prouve que le test ci-dessus mesure bien le chiffrement et non un artefact.
    expect($queued['encrypted'])->toBeFalse()
        ->and($queued['command'])->toStartWith('O:')
        ->and($queued['payload'])->toContain('203.0.x.x');
});
