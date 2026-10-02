<?php

declare(strict_types=1);

use App\Models\ServiceConnection;
use App\Services\Seerr\SeerrRequestBusy;
use App\Services\Seerr\SeerrRequestLock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Sleep;
use Tests\TestCase;

uses(TestCase::class);

function seerrRequestLockConnection(): ServiceConnection
{
    $serviceConnection = new ServiceConnection;
    $serviceConnection->id = 3;

    return $serviceConnection;
}

test('it runs the callback, returns its value and releases the lock', function (): void {
    $seerrRequestLock = new SeerrRequestLock;

    expect($seerrRequestLock->run(seerrRequestLockConnection(), 41, fn (): string => 'done'))->toBe('done')
        ->and(Cache::lock(SeerrRequestLock::key(3, 41), 1)->get())->toBeTrue();
});

test('it refuses at once while another write holds the request', function (): void {
    Cache::lock(SeerrRequestLock::key(3, 41), SeerrRequestLock::TTL_SECONDS)->get();
    $ran = false;

    expect(fn () => (new SeerrRequestLock)->run(seerrRequestLockConnection(), 41, function () use (&$ran): void {
        $ran = true;
    }))->toThrow(SeerrRequestBusy::class);

    expect($ran)->toBeFalse();
});

test('a waiting caller gives up after its wait', function (): void {
    Sleep::fake(syncWithCarbon: true);
    Cache::lock(SeerrRequestLock::key(3, 41), SeerrRequestLock::TTL_SECONDS)->get();

    expect(fn (): mixed => (new SeerrRequestLock)->run(seerrRequestLockConnection(), 41, fn (): bool => true, 2))
        ->toThrow(SeerrRequestBusy::class);
});

test('the lock is released when the callback throws', function (): void {
    expect(fn (): mixed => (new SeerrRequestLock)->run(seerrRequestLockConnection(), 41, fn (): never => throw new RuntimeException('boom')))
        ->toThrow(RuntimeException::class, 'boom');

    expect(Cache::lock(SeerrRequestLock::key(3, 41), 1)->get())->toBeTrue();
});
