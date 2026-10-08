<?php

declare(strict_types=1);

use App\Enums\Ability;
use App\Models\User;
use App\Support\Abilities;
use Illuminate\Support\Facades\Gate;

function abilitiesTestUser(string $role): User
{
    return match ($role) {
        'viewer' => User::factory()->create(),
        'member' => User::factory()->member()->create(),
        'admin' => User::factory()->admin()->create(),
        default => throw new InvalidArgumentException($role),
    };
}

test('each role holds exactly the abilities its minimum role grants', function (string $role, array $expected): void {
    expect(Abilities::for(abilitiesTestUser($role)))->toBe($expected);
})->with([
    'viewer' => ['viewer', ['view-library' => true, 'request-media' => true, 'manage-library' => false, 'manage-requests' => false, 'admin' => false]],
    'member' => ['member', ['view-library' => true, 'request-media' => true, 'manage-library' => true, 'manage-requests' => true, 'admin' => false]],
    'admin' => ['admin', ['view-library' => true, 'request-media' => true, 'manage-library' => true, 'manage-requests' => true, 'admin' => true]],
]);

test('a guest holds no ability', function (): void {
    expect(Abilities::for(null))->toBe([
        'view-library' => false,
        'request-media' => false,
        'manage-library' => false,
        'manage-requests' => false,
        'admin' => false,
    ]);
});

test('every ability is registered as a gate', function (): void {
    foreach (array_keys(Abilities::MINIMUM_ROLES) as $ability) {
        expect(Gate::has($ability))->toBeTrue();
    }
});

test('the gates follow the role hierarchy', function (): void {
    expect(abilitiesTestUser('viewer')->can(Abilities::MANAGE_LIBRARY))->toBeFalse()
        ->and(abilitiesTestUser('member')->can(Abilities::MANAGE_LIBRARY))->toBeTrue()
        ->and(abilitiesTestUser('member')->can(Abilities::ADMIN))->toBeFalse()
        ->and(abilitiesTestUser('admin')->can(Abilities::ADMIN))->toBeTrue();
});

test('the ability names come from the Ability enum, in its order', function (): void {
    expect(array_keys(Abilities::MINIMUM_ROLES))->toBe(Ability::values())
        ->and([Abilities::VIEW_LIBRARY, Abilities::REQUEST_MEDIA, Abilities::MANAGE_LIBRARY, Abilities::MANAGE_REQUESTS, Abilities::ADMIN])->toBe(Ability::values());
});
