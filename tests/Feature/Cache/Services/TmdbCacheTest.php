<?php

declare(strict_types=1);

use App\Cache\Services\TmdbCache;
use Illuminate\Support\Facades\Cache;

beforeEach(function (): void {
    Cache::store('array')->flush();
});

test('TmdbCache caches and busts under the bare service tag', function (): void {
    $cache = new TmdbCache;

    $cache->rememberMetadata('movie:1', fn (): array => ['t' => 'first']);
    $hits = 0;
    $cache->rememberMetadata('movie:1', function () use (&$hits): array {
        $hits++;

        return ['t' => 'second'];
    });

    expect($hits)->toBe(0);

    $cache->bustAll();

    $cache->rememberMetadata('movie:1', function () use (&$hits): array {
        $hits++;

        return ['t' => 'fresh'];
    });

    expect($hits)->toBe(1);
});
