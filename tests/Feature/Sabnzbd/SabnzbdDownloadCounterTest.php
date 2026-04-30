<?php

declare(strict_types=1);

use App\Events\SabnzbdDownloadCountsChanged;
use App\Models\ServiceConnection;
use App\Services\Sabnzbd\SabnzbdDownloadCounter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Http::preventStrayRequests();
    Cache::forget(SabnzbdDownloadCounter::CACHE_KEY);
});

test('recompute counts queue + history slots and broadcasts', function (): void {
    ServiceConnection::factory()->sabnzbd()->create([
        'url' => 'http://sab.local:8080',
        'api_key' => 'k',
    ]);

    Http::fake([
        'sab.local:8080/api*' => Http::sequence()
            ->push(['queue' => ['slots' => [['nzo_id' => 'a'], ['nzo_id' => 'b']]]])
            ->push(['history' => ['slots' => [['nzo_id' => 'h1']]]]),
    ]);

    Event::fake([SabnzbdDownloadCountsChanged::class]);

    $counts = resolve(SabnzbdDownloadCounter::class)->recompute();

    expect($counts)->toBe(['queued' => 2, 'completed' => 1]);
    expect(Cache::get(SabnzbdDownloadCounter::CACHE_KEY))->toBe(['queued' => 2, 'completed' => 1]);

    Event::assertDispatched(fn (SabnzbdDownloadCountsChanged $sabnzbdDownloadCountsChanged): bool => $sabnzbdDownloadCountsChanged->queued === 2 && $sabnzbdDownloadCountsChanged->completed === 1);
});

test('recompute returns zeros when no SABnzbd connection is configured', function (): void {
    Event::fake([SabnzbdDownloadCountsChanged::class]);

    $counts = resolve(SabnzbdDownloadCounter::class)->recompute();

    expect($counts)->toBe(['queued' => 0, 'completed' => 0]);
    Event::assertDispatched(SabnzbdDownloadCountsChanged::class);
});

test('recompute keeps the other count when one upstream call fails', function (): void {
    ServiceConnection::factory()->sabnzbd()->create([
        'url' => 'http://sab.local:8080',
    ]);

    // Sabnzbd is mode-routed via query string. Use a callable fake so we
    // can match on `mode=` regardless of where it lands in the query.
    Http::fake(function ($request) {
        $url = (string) $request->url();
        if (str_contains($url, 'mode=queue')) {
            return Http::response(['queue' => ['slots' => [['nzo_id' => 'a']]]]);
        }

        if (str_contains($url, 'mode=history')) {
            return Http::response('Server Error', 500);
        }

        return Http::response('not faked', 404);
    });

    $counts = resolve(SabnzbdDownloadCounter::class)->recompute();

    expect($counts)->toBe(['queued' => 1, 'completed' => 0]);
});

test('get returns zeros when cache is empty', function (): void {
    expect(resolve(SabnzbdDownloadCounter::class)->get())->toBe(['queued' => 0, 'completed' => 0]);
});

test('get returns the cached value', function (): void {
    Cache::put(SabnzbdDownloadCounter::CACHE_KEY, ['queued' => 5, 'completed' => 2], 60);

    expect(resolve(SabnzbdDownloadCounter::class)->get())->toBe(['queued' => 5, 'completed' => 2]);
});
