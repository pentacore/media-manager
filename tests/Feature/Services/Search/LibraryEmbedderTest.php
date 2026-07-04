<?php

declare(strict_types=1);

use App\Models\IndexedMovie;
use App\Models\IndexedSeries;
use App\Services\Search\LibraryEmbedder;
use Illuminate\Support\Collection;
use Laravel\Ai\Embeddings;

test('embeddingText folds title, year, genres, and overview', function (): void {
    $movie = IndexedMovie::factory()->make([
        'title' => 'Dark City',
        'year' => 1998,
        'genres' => ['Sci-Fi', 'Noir'],
        'overview' => 'A man struggles with memories.',
    ]);

    $text = (new LibraryEmbedder)->embeddingText($movie);

    expect($text)->toContain('Dark City')
        ->toContain('1998')
        ->toContain('Sci-Fi')
        ->toContain('Noir')
        ->toContain('A man struggles with memories.');
});

test('embed returns a vector of the configured dimensions when enabled', function (): void {
    config()->set('mediamanager.ai.enabled', true);
    Embeddings::fake();

    $series = IndexedSeries::factory()->make(['title' => 'Severance', 'overview' => 'Work-life split.']);

    $vector = (new LibraryEmbedder)->embed($series);

    expect($vector)->toBeArray()->toHaveCount(LibraryEmbedder::DIMENSIONS);
    Embeddings::assertGenerated(fn (): bool => true);
});

test('embed returns null when ai is disabled', function (): void {
    config()->set('mediamanager.ai.enabled', false);
    Embeddings::fake();

    $vector = (new LibraryEmbedder)->embed(IndexedMovie::factory()->make());

    expect($vector)->toBeNull();
    Embeddings::assertNothingGenerated();
});

test('embedMany makes a single batched generation call and returns one vector per item', function (): void {
    config()->set('mediamanager.ai.enabled', true);
    Embeddings::fake();

    $items = new Collection([
        IndexedMovie::factory()->make(['title' => 'Alpha']),
        IndexedSeries::factory()->make(['title' => 'Beta']),
    ]);

    $vectors = (new LibraryEmbedder)->embedMany($items);

    expect($vectors)->toBeArray()->toHaveCount(2)
        ->and($vectors[0])->toHaveCount(LibraryEmbedder::DIMENSIONS)
        ->and($vectors[1])->toHaveCount(LibraryEmbedder::DIMENSIONS);

    $generations = 0;
    Embeddings::assertGenerated(function () use (&$generations): bool {
        $generations++;

        return true;
    });

    expect($generations)->toBe(1);
});

test('embedMany returns nulls without generating when ai is disabled', function (): void {
    config()->set('mediamanager.ai.enabled', false);
    Embeddings::fake();

    $vectors = (new LibraryEmbedder)->embedMany(new Collection([
        IndexedMovie::factory()->make(),
        IndexedMovie::factory()->make(),
    ]));

    expect($vectors)->toBe([null, null]);
    Embeddings::assertNothingGenerated();
});

test('enabled reflects the mediamanager ai config flag', function (): void {
    config()->set('mediamanager.ai.enabled', true);
    expect((new LibraryEmbedder)->enabled())->toBeTrue();

    config()->set('mediamanager.ai.enabled', false);
    expect((new LibraryEmbedder)->enabled())->toBeFalse();
});
