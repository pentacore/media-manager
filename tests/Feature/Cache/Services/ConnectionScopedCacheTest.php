<?php

declare(strict_types=1);

use App\Cache\Services\ProwlarrCache;
use App\Cache\Services\RadarrCache;
use App\Cache\Services\SabnzbdCache;
use App\Cache\Services\SeerrCache;
use App\Cache\Services\SonarrCache;
use App\Cache\Services\WhisparrCache;
use App\Models\ServiceConnection;
use Illuminate\Support\Facades\Cache;

beforeEach(function (): void {
    config()->set('mediamanager.cache.store', 'array');
    config()->set('mediamanager.cache.ttl.list', 60);
    config()->set('mediamanager.cache.ttl.entity', 300);
    config()->set('mediamanager.cache.ttl.metadata', 600);
    Cache::store('array')->flush();
});

/**
 * Each per-connection cache class and its service slug (also the
 * ServiceConnection factory state).
 *
 * @return array<string, array{0: class-string, 1: string}>
 */
function connectionScopedCacheCases(): array
{
    return [
        'sonarr' => [SonarrCache::class, 'sonarr'],
        'radarr' => [RadarrCache::class, 'radarr'],
        'sabnzbd' => [SabnzbdCache::class, 'sabnzbd'],
        'seerr' => [SeerrCache::class, 'seerr'],
        'prowlarr' => [ProwlarrCache::class, 'prowlarr'],
        'whisparr' => [WhisparrCache::class, 'whisparr'],
    ];
}

test('each per-connection cache keys and tags its entries by service slug and connection id', function (string $cacheClass, string $slug): void {
    $serviceConnection = ServiceConnection::factory()->{$slug}()->create();
    $cache = new $cacheClass($serviceConnection);

    $cache->rememberList('list', fn (): array => ['from' => $slug]);

    $prefix = sprintf('%s:%d', $slug, $serviceConnection->id);

    expect(Cache::store('array')->tags([$prefix])->get(sprintf('%s:list', $prefix)))->toBe(['from' => $slug]);
})->with(connectionScopedCacheCases());

test('each per-connection cache takes its bucket lifetimes from the shared cache config', function (string $cacheClass, string $slug): void {
    $cache = new $cacheClass(ServiceConnection::factory()->{$slug}()->create());
    $misses = new stdClass;
    $misses->list = 0;
    $misses->entity = 0;
    $misses->metadata = 0;

    $read = function () use ($cache, $misses): void {
        $cache->rememberList('list', function () use ($misses): array {
            $misses->list++;

            return [];
        });
        $cache->rememberEntity('item:1', function () use ($misses): array {
            $misses->entity++;

            return [];
        });
        $cache->rememberMetadata('quality-profiles', function () use ($misses): array {
            $misses->metadata++;

            return [];
        });
    };

    $read();
    $this->travel(61)->seconds();
    $read();

    // list lives 60 s; entity (300 s) and metadata (600 s) are still cached.
    expect($misses->list)->toBe(2)
        ->and($misses->entity)->toBe(1)
        ->and($misses->metadata)->toBe(1);
})->with(connectionScopedCacheCases());
