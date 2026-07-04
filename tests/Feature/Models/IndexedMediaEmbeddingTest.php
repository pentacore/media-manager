<?php

declare(strict_types=1);

use App\Models\IndexedMovie;
use App\Models\IndexedSeries;

test('embedding is cast to array and included in searchable array when set', function (): void {
    $movie = IndexedMovie::factory()->create(['embedding' => [0.1, 0.2, 0.3]]);

    expect($movie->refresh()->embedding)->toBe([0.1, 0.2, 0.3])
        ->and($movie->toSearchableArray())->toHaveKey('embedding');
});

test('searchable array omits embedding when null', function (): void {
    $series = IndexedSeries::factory()->create(['embedding' => null]);

    expect($series->toSearchableArray())->not->toHaveKey('embedding');
});
