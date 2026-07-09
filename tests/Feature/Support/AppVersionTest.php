<?php

declare(strict_types=1);

use App\Support\AppVersion;
use Illuminate\Support\Facades\Cache;

test('current returns the configured version', function (): void {
    config()->set('app.version', '1.7.2');

    expect(AppVersion::current())->toBe('1.7.2');
});

test('current falls back to dev when config is empty', function (): void {
    config()->set('app.version', '');

    expect(AppVersion::current())->toBe('dev');
});

test('latest reads the cached release version', function (): void {
    Cache::put(AppVersion::CACHE_KEY, '1.8.0', 60);

    expect(AppVersion::latest())->toBe('1.8.0');
});

test('latest returns null when cache is empty', function (): void {
    Cache::forget(AppVersion::CACHE_KEY);

    expect(AppVersion::latest())->toBeNull();
});

test('updateAvailable is true when latest is newer', function (): void {
    config()->set('app.version', '1.7.2');
    Cache::put(AppVersion::CACHE_KEY, '1.8.0', 60);

    expect(AppVersion::updateAvailable())->toBeTrue();
});

test('updateAvailable is false when versions are equal', function (): void {
    config()->set('app.version', '1.8.0');
    Cache::put(AppVersion::CACHE_KEY, '1.8.0', 60);

    expect(AppVersion::updateAvailable())->toBeFalse();
});

test('updateAvailable is false when latest is older', function (): void {
    config()->set('app.version', '1.8.0');
    Cache::put(AppVersion::CACHE_KEY, '1.7.2', 60);

    expect(AppVersion::updateAvailable())->toBeFalse();
});

test('updateAvailable is false when current is dev', function (): void {
    config()->set('app.version', 'dev');
    Cache::put(AppVersion::CACHE_KEY, '1.8.0', 60);

    expect(AppVersion::updateAvailable())->toBeFalse();
});

test('updateAvailable is false when no latest version is cached', function (): void {
    config()->set('app.version', '1.7.2');
    Cache::forget(AppVersion::CACHE_KEY);

    expect(AppVersion::updateAvailable())->toBeFalse();
});
