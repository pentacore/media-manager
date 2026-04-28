<?php

declare(strict_types=1);

use App\Cache\Services\ProwlarrCache;
use App\Models\ServiceConnection;
use Illuminate\Support\Facades\Cache;

beforeEach(function (): void {
    config()->set('mediamanager.cache.store', 'array');
    config()->set('mediamanager.cache.ttl.list', 60);
    config()->set('mediamanager.cache.ttl.entity', 300);
    config()->set('mediamanager.cache.ttl.metadata', 600);
    Cache::store('array')->flush();

    $this->connection = ServiceConnection::factory()->prowlarr()->create([
        'url' => 'http://prowlarr.local:9696',
        'api_key' => 'k',
    ]);
});

test('ProwlarrCache::bustAll only flushes entries for this connection', function (): void {
    $other = ServiceConnection::factory()->prowlarr()->create();

    $a = new ProwlarrCache($this->connection);
    $b = new ProwlarrCache($other);

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
