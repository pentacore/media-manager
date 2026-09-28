<?php

declare(strict_types=1);

use App\Actions\LastAdminException;
use App\Actions\ModifyUserAccess;
use App\Enums\UserRole;
use App\Models\User;

test('demoting the only admin is refused', function (): void {
    $admin = User::factory()->admin()->create();
    User::factory()->member()->create();

    expect(fn () => resolve(ModifyUserAccess::class)->changeRole($admin, UserRole::Member))
        ->toThrow(LastAdminException::class);

    expect($admin->fresh()->role)->toBe(UserRole::Admin);
});

test('deleting the only admin is refused', function (): void {
    $admin = User::factory()->admin()->create();

    expect(fn () => resolve(ModifyUserAccess::class)->delete($admin))->toThrow(LastAdminException::class);

    expect($admin->fresh())->not->toBeNull();
});

test('one of two admins can be demoted and deleted', function (): void {
    $first = User::factory()->admin()->create();
    $second = User::factory()->admin()->create();

    resolve(ModifyUserAccess::class)->changeRole($second, UserRole::Viewer);
    expect($second->fresh()->role)->toBe(UserRole::Viewer);

    $third = User::factory()->admin()->create();
    resolve(ModifyUserAccess::class)->delete($third);
    expect($third->fresh())->toBeNull()
        ->and($first->fresh()->role)->toBe(UserRole::Admin);
});

test('non-admins change freely while a single admin exists', function (): void {
    User::factory()->admin()->create();
    $member = User::factory()->member()->create();

    resolve(ModifyUserAccess::class)->changeRole($member, UserRole::Viewer);
    resolve(ModifyUserAccess::class)->delete($member);

    expect($member->fresh())->toBeNull();
});

test('the before-delete hook runs only when the delete is allowed', function (): void {
    $admin = User::factory()->admin()->create();
    $ran = false;

    expect(fn () => resolve(ModifyUserAccess::class)->delete($admin, function () use (&$ran): void {
        $ran = true;
    }))->toThrow(LastAdminException::class);

    expect($ran)->toBeFalse();
});
