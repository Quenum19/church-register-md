<?php

use App\Enums\Ability;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

it('accorde à chaque rôle exactement les abilities du contrat', function (Role $role, array $allowed): void {
    $user = User::factory()->role($role)->create();

    foreach (Ability::cases() as $ability) {
        expect(Gate::forUser($user)->allows($ability->value))
            ->toBe(in_array($ability->value, $allowed, true), "{$role->value} / {$ability->value}");
    }

    expect($user->abilities())->toBe($allowed);
})->with([
    'lecteur' => [Role::Lecteur, ['visitors.view']],
    'moderateur' => [Role::Moderateur, ['visitors.view', 'visitors.update', 'notes.create']],
    'super_admin' => [Role::SuperAdmin, Ability::values()],
]);

it('refuse tout à un compte désactivé', function (): void {
    $user = User::factory()->superAdmin()->inactive()->create();

    foreach (Ability::cases() as $ability) {
        expect(Gate::forUser($user)->denies($ability->value))->toBeTrue();
    }

    expect($user->abilities())->toBe([]);
});

it('refuse tout à un invité', function (): void {
    expect(Gate::allows('visitors.view'))->toBeFalse();
});
