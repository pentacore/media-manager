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
        public function __construct(private readonly ?int $connId) {}

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
    $baseServiceCache = fakeCache();
    $hits = 0;

    $a = $baseServiceCache->rememberList('list', function () use (&$hits): array {
        $hits++;

        return ['a' => 1];
    });

    $b = $baseServiceCache->rememberList('list', function () use (&$hits): array {
        $hits++;

        return ['a' => 999];
    });

    expect($a)->toBe(['a' => 1]);
    expect($b)->toBe(['a' => 1]);
    expect($hits)->toBe(1);
});

test('bustAll forgets every entry tagged with this service+connection', function (): void {
    $baseServiceCache = fakeCache(7);
    $baseServiceCache->rememberList('list', fn (): array => ['l' => 1]);
    $baseServiceCache->rememberEntity('series:42', fn (): array => ['e' => 1]);

    $baseServiceCache->bustAll();

    $hits = 0;
    $baseServiceCache->rememberList('list', function () use (&$hits): array {
        $hits++;

        return ['l' => 2];
    });

    expect($hits)->toBe(1);
});

test('bustAll on connection 7 does NOT bust connection 9', function (): void {
    $baseServiceCache = fakeCache(7);
    $b = fakeCache(9);

    $baseServiceCache->rememberList('list', fn (): array => ['from' => 7]);
    $b->rememberList('list', fn (): array => ['from' => 9]);

    $baseServiceCache->bustAll();

    $hits = 0;
    $still = $b->rememberList('list', function () use (&$hits): array {
        $hits++;

        return ['from' => 'fresh'];
    });

    expect($still)->toBe(['from' => 9]);
    expect($hits)->toBe(0);
});

test('connectionId of null tags only the bare service name (Tmdb/Trakt shape)', function (): void {
    $baseServiceCache = fakeCache(null);
    $baseServiceCache->rememberMetadata('title:1', fn (): array => ['t' => 1]);

    $baseServiceCache->bustAll();

    $hits = 0;
    $baseServiceCache->rememberMetadata('title:1', function () use (&$hits): array {
        $hits++;

        return ['t' => 'fresh'];
    });

    expect($hits)->toBe(1);
});

test('different ttl buckets each persist correctly', function (): void {
    $baseServiceCache = fakeCache();

    expect($baseServiceCache->rememberList('a', fn (): string => 'list'))->toBe('list');
    expect($baseServiceCache->rememberEntity('b', fn (): string => 'entity'))->toBe('entity');
    expect($baseServiceCache->rememberMetadata('c', fn (): string => 'meta'))->toBe('meta');

    $hits = 0;
    $baseServiceCache->rememberList('a', function () use (&$hits): string {
        $hits++;

        return 'x';
    });
    $baseServiceCache->rememberEntity('b', function () use (&$hits): string {
        $hits++;

        return 'x';
    });
    $baseServiceCache->rememberMetadata('c', function () use (&$hits): string {
        $hits++;

        return 'x';
    });

    expect($hits)->toBe(0);
});
