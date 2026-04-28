<?php

declare(strict_types=1);

use App\Cache\Services\BaseServiceCache;
use Illuminate\Support\Facades\Cache;

beforeEach(function (): void {
    config()->set('mediamanager.cache.store', 'array');
    config()->set('mediamanager.cache.ttl.list', 60);
    config()->set('mediamanager.cache.ttl.entity', 300);
    config()->set('mediamanager.cache.ttl.metadata', 600);
    Cache::store('array')->flush();
});

function fakeCache(?int $connId = 7): BaseServiceCache
{
    return new class($connId) extends BaseServiceCache
    {
        public function __construct(private ?int $connId) {}

        protected function service(): string
        {
            return 'fake';
        }

        protected function connectionId(): ?int
        {
            return $this->connId;
        }

        protected function ttls(): array
        {
            return config('mediamanager.cache.ttl');
        }
    };
}

test('rememberList caches the closure result and reuses on second call', function (): void {
    $cache = fakeCache();
    $hits = 0;

    $a = $cache->rememberList('list', function () use (&$hits): array {
        $hits++;

        return ['a' => 1];
    });

    $b = $cache->rememberList('list', function () use (&$hits): array {
        $hits++;

        return ['a' => 999];
    });

    expect($a)->toBe(['a' => 1]);
    expect($b)->toBe(['a' => 1]);
    expect($hits)->toBe(1);
});

test('bustAll forgets every entry tagged with this service+connection', function (): void {
    $cache = fakeCache(7);
    $cache->rememberList('list', fn () => ['l' => 1]);
    $cache->rememberEntity('series:42', fn () => ['e' => 1]);

    $cache->bustAll();

    $hits = 0;
    $cache->rememberList('list', function () use (&$hits): array {
        $hits++;

        return ['l' => 2];
    });

    expect($hits)->toBe(1);
});

test('bustAll on connection 7 does NOT bust connection 9', function (): void {
    $a = fakeCache(7);
    $b = fakeCache(9);

    $a->rememberList('list', fn () => ['from' => 7]);
    $b->rememberList('list', fn () => ['from' => 9]);

    $a->bustAll();

    $hits = 0;
    $still = $b->rememberList('list', function () use (&$hits): array {
        $hits++;

        return ['from' => 'fresh'];
    });

    expect($still)->toBe(['from' => 9]);
    expect($hits)->toBe(0);
});

test('connectionId of null tags only the bare service name (Tmdb/Trakt shape)', function (): void {
    $cache = fakeCache(null);
    $cache->rememberMetadata('title:1', fn () => ['t' => 1]);

    $cache->bustAll();

    $hits = 0;
    $cache->rememberMetadata('title:1', function () use (&$hits): array {
        $hits++;

        return ['t' => 'fresh'];
    });

    expect($hits)->toBe(1);
});

test('different ttl buckets each persist correctly', function (): void {
    $cache = fakeCache();

    expect($cache->rememberList('a', fn () => 'list'))->toBe('list');
    expect($cache->rememberEntity('b', fn () => 'entity'))->toBe('entity');
    expect($cache->rememberMetadata('c', fn () => 'meta'))->toBe('meta');

    $hits = 0;
    $cache->rememberList('a', function () use (&$hits) {
        $hits++;

        return 'x';
    });
    $cache->rememberEntity('b', function () use (&$hits) {
        $hits++;

        return 'x';
    });
    $cache->rememberMetadata('c', function () use (&$hits) {
        $hits++;

        return 'x';
    });

    expect($hits)->toBe(0);
});
