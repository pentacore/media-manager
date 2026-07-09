<?php

declare(strict_types=1);

use App\Support\AppVersion;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Http::preventStrayRequests();
});

test('caches the latest release version', function (): void {
    Http::fake([
        'api.github.com/repos/pentacore/media-manager/releases/latest' => Http::response(['tag_name' => 'v1.8.0']),
    ]);

    $this->artisan('app:check-version')->assertSuccessful();

    expect(Cache::get(AppVersion::CACHE_KEY))->toBe('1.8.0');
});

test('leaves the cache untouched when the release lookup fails', function (): void {
    Cache::put(AppVersion::CACHE_KEY, '1.7.9', 60);

    Http::fake([
        'api.github.com/*' => Http::response(null, 500),
    ]);

    $this->artisan('app:check-version')->assertSuccessful();

    expect(Cache::get(AppVersion::CACHE_KEY))->toBe('1.7.9');
});

test('does not populate the cache when the repo is unreachable', function (): void {
    Http::fake([
        'api.github.com/*' => Http::response(null, 404),
    ]);

    $this->artisan('app:check-version')->assertSuccessful();

    expect(Cache::get(AppVersion::CACHE_KEY))->toBeNull();
});
