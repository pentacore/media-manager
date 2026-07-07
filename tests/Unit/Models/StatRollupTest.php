<?php

declare(strict_types=1);

use App\Models\StatRollup;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('persists a rollup row with jsonb dimensions', function (): void {
    $rollup = StatRollup::factory()->create(['dimensions' => ['service' => 'radarr', 'event' => 'Download']]);

    expect($rollup->fresh()->dimensions)->toEqualCanonicalizing(['event' => 'Download', 'service' => 'radarr'])
        ->and($rollup->count)->toBeGreaterThan(0);
});

it('enforces uniqueness on metric period bucket dimensions', function (): void {
    $rollup = StatRollup::factory()->create();

    StatRollup::factory()->create([
        'metric' => $rollup->metric,
        'period' => $rollup->period,
        'bucket' => $rollup->bucket,
        'dimensions' => $rollup->dimensions,
    ]);
})->throws(QueryException::class);
