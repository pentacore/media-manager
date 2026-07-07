<?php

declare(strict_types=1);

use App\Models\EmbyActivity;
use App\Models\StatRollup;

it('backfills historical rollups day by day', function (): void {
    EmbyActivity::factory()->count(2)->create(['action' => 'played', 'media_type' => 'Movie', 'created_at' => now()->subDays(40)]);
    EmbyActivity::factory()->create(['action' => 'played', 'media_type' => 'Movie', 'created_at' => now()->subDays(2)]);

    $this->artisan('statistics:backfill')->assertSuccessful();

    expect((int) StatRollup::query()->where(['metric' => 'watch.plays', 'period' => 'day'])->sum('count'))->toBe(3);
});

it('produces identical rollups when run twice', function (): void {
    EmbyActivity::factory()->count(3)->create(['action' => 'played', 'media_type' => 'Movie', 'created_at' => now()->subDays(3)]);

    $this->artisan('statistics:backfill')->assertSuccessful();
    $this->artisan('statistics:backfill')->assertSuccessful();

    expect((int) StatRollup::query()->where(['metric' => 'watch.plays', 'period' => 'day'])->sum('count'))->toBe(3);
});
