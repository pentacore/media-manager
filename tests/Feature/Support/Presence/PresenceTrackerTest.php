<?php

declare(strict_types=1);

use App\Support\Presence\PresenceTracker;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Redis;

beforeEach(function (): void {
    // Per-process unique key prevents parallel-worker pollution.
    config()->set('mediamanager.presence.key', 'presence:users:test-'.getmypid());
    config()->set('mediamanager.presence.heartbeat_ttl', 90);
    Redis::connection()->del(config('mediamanager.presence.key'));
});

afterEach(function (): void {
    Redis::connection()->del(config('mediamanager.presence.key'));
});

test('hasActiveUsers is false when nobody has marked themselves active', function (): void {
    expect(resolve(PresenceTracker::class)->hasActiveUsers())->toBeFalse();
});

test('markActive followed by hasActiveUsers reports true', function (): void {
    $presenceTracker = resolve(PresenceTracker::class);

    $presenceTracker->markActive('user-1');

    expect($presenceTracker->hasActiveUsers())->toBeTrue();
});

test('activeCount tallies distinct users', function (): void {
    $presenceTracker = resolve(PresenceTracker::class);

    $presenceTracker->markActive('user-a');
    $presenceTracker->markActive('user-b');
    $presenceTracker->markActive('user-a'); // repeat → still one entry

    expect($presenceTracker->activeCount())->toBe(2);
});

test('markActive refreshes a stale score with a future one', function (): void {
    $presenceTracker = resolve(PresenceTracker::class);

    Redis::connection()->zadd(
        config('mediamanager.presence.key'),
        Date::now()->subSeconds(1000)->getTimestamp(),
        'user-1',
    );

    $presenceTracker->markActive('user-1');

    $score = (int) Redis::connection()->zscore(
        config('mediamanager.presence.key'),
        'user-1',
    );

    expect($score)->toBeGreaterThan(Date::now()->getTimestamp());
});

test('hasActiveUsers prunes entries whose expiry has passed', function (): void {
    Redis::connection()->zadd(
        config('mediamanager.presence.key'),
        Date::now()->subMinutes(1)->getTimestamp(),
        'stale-user',
    );

    expect((int) Redis::connection()->zcard(config('mediamanager.presence.key')))->toBe(1);

    $presenceTracker = resolve(PresenceTracker::class);

    expect($presenceTracker->hasActiveUsers())->toBeFalse();
    expect((int) Redis::connection()->zcard(config('mediamanager.presence.key')))->toBe(0);
});

test('activeCount also prunes expired entries before counting', function (): void {
    $key = config('mediamanager.presence.key');
    Redis::connection()->zadd($key, Date::now()->subSeconds(30)->getTimestamp(), 'expired');
    Redis::connection()->zadd($key, Date::now()->getTimestamp() + 30, 'fresh');

    expect(resolve(PresenceTracker::class)->activeCount())->toBe(1);
});

test('markActive returns true when the presence set was empty before the call', function (): void {
    $presenceTracker = resolve(PresenceTracker::class);

    expect($presenceTracker->markActive('user-1'))->toBeTrue();
});

test('markActive returns false once another user is already present', function (): void {
    $presenceTracker = resolve(PresenceTracker::class);

    $presenceTracker->markActive('user-1');

    expect($presenceTracker->markActive('user-2'))->toBeFalse();
    expect($presenceTracker->markActive('user-1'))->toBeFalse();
});

test('markActive returns true again when expired entries make the set effectively empty', function (): void {
    $presenceTracker = resolve(PresenceTracker::class);

    Redis::connection()->zadd(
        config('mediamanager.presence.key'),
        Date::now()->subSeconds(60)->getTimestamp(),
        'stale-user',
    );

    expect($presenceTracker->markActive('user-fresh'))->toBeTrue();
});
