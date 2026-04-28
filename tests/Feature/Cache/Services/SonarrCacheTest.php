<?php

declare(strict_types=1);

use App\Cache\Services\SonarrCache;
use App\Models\ServiceConnection;
use Illuminate\Support\Facades\Cache;

beforeEach(function (): void {
    config()->set('mediamanager.cache.store', 'array');
    config()->set('mediamanager.cache.ttl.list', 60);
    config()->set('mediamanager.cache.ttl.entity', 300);
    config()->set('mediamanager.cache.ttl.metadata', 600);
    Cache::store('array')->flush();

    $this->connection = ServiceConnection::factory()->sonarr()->create([
        'url' => 'http://sonarr.local:8989',
        'api_key' => 'k',
    ]);
});

test('SonarrCache::bustAll only flushes entries for this connection', function (): void {
    $other = ServiceConnection::factory()->sonarr()->create();

    $a = new SonarrCache($this->connection);
    $b = new SonarrCache($other);

    $a->rememberList('list', fn (): array => ['for' => $this->connection->id]);
    $b->rememberList('list', fn (): array => ['for' => $other->id]);

    $a->bustAll();

    $hits = 0;
    $b->rememberList('list', function () use (&$hits): array {
        $hits++;

        return ['fresh' => true];
    });

    expect($hits)->toBe(0);
});
