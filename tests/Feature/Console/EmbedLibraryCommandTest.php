<?php

declare(strict_types=1);

use App\Models\IndexedMovie;
use App\Models\IndexedSeries;
use Laravel\Ai\Embeddings;

test('ai:embed-library embeds items missing vectors across both models', function (): void {
    config()->set('mediamanager.ai.enabled', true);
    Embeddings::fake();

    IndexedMovie::factory()->count(3)->create(['embedding' => null]);
    IndexedSeries::factory()->count(2)->create(['embedding' => null]);

    $this->artisan('ai:embed-library', ['--missing-only' => true])->assertSuccessful();

    expect(IndexedMovie::whereNull('embedding')->count())->toBe(0)
        ->and(IndexedSeries::whereNull('embedding')->count())->toBe(0);
});

test('ai:embed-library no-ops when ai is disabled', function (): void {
    config()->set('mediamanager.ai.enabled', false);
    Embeddings::fake();

    IndexedMovie::factory()->create(['embedding' => null]);

    $this->artisan('ai:embed-library')->assertSuccessful();

    expect(IndexedMovie::whereNull('embedding')->count())->toBe(1);
    Embeddings::assertNothingGenerated();
});
