<?php

declare(strict_types=1);

use App\Models\EmbyUserLink;
use App\Models\ServiceConnection;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The SeerrUserResolver cache key for this user's match on the connection.
 */
function embyLinkObserverMatchKey(ServiceConnection $serviceConnection, User $user): string
{
    return sprintf('seerr:user-match:%d:%d', $serviceConnection->id, $user->id);
}

test('linking forgets the cached Seerr match only once the link has committed', function (): void {
    $seerr = ServiceConnection::factory()->seerr()->create();
    $user = User::factory()->create();
    $key = embyLinkObserverMatchKey($seerr, $user);
    Cache::put($key, ['id' => 5], 600);
    $seen = new stdClass;

    DB::transaction(static function () use ($user, $key, $seen): void {
        EmbyUserLink::factory()->create(['user_id' => $user->id]);
        $seen->cachedInsideTransaction = Cache::has($key);
    });

    expect($seen->cachedInsideTransaction)->toBeTrue()
        ->and(Cache::has($key))->toBeFalse();
});

test('unlinking forgets the cached Seerr match only once the delete has committed', function (): void {
    $seerr = ServiceConnection::factory()->seerr()->create();
    $user = User::factory()->create();
    $link = EmbyUserLink::factory()->create(['user_id' => $user->id]);
    $key = embyLinkObserverMatchKey($seerr, $user);
    Cache::put($key, ['id' => 5], 600);
    $seen = new stdClass;

    DB::transaction(static function () use ($link, $key, $seen): void {
        $link->delete();
        $seen->cachedInsideTransaction = Cache::has($key);
    });

    expect($seen->cachedInsideTransaction)->toBeTrue()
        ->and(Cache::has($key))->toBeFalse();
});

test('a link rolled back never touches the cached Seerr match', function (): void {
    $seerr = ServiceConnection::factory()->seerr()->create();
    $user = User::factory()->create();
    $key = embyLinkObserverMatchKey($seerr, $user);
    Cache::put($key, ['id' => 5], 600);

    try {
        DB::transaction(static function () use ($user): never {
            EmbyUserLink::factory()->create(['user_id' => $user->id]);

            throw new RuntimeException('Roll back the link.');
        });
    } catch (RuntimeException $runtimeException) {
        expect($runtimeException->getMessage())->toBe('Roll back the link.');
    }

    expect(Cache::has($key))->toBeTrue();
});
