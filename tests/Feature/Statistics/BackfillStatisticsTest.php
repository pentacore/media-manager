<?php

declare(strict_types=1);

use App\Models\EmbyActivity;
use App\Models\StatRollup;
use Carbon\CarbonImmutable;

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

it('starts the backfill early enough for an event just after local midnight', function (): void {
    config()->set('app.timezone', 'Europe/Stockholm');
    date_default_timezone_set('Europe/Stockholm');
    $this->travelTo(CarbonImmutable::parse('2026-07-06 12:00:00', 'Europe/Stockholm'));

    // 00:30 in Stockholm on the 6th is 22:30 UTC on the 5th.
    EmbyActivity::factory()->create(['action' => 'played', 'media_type' => 'Movie', 'created_at' => CarbonImmutable::parse('2026-07-06 00:30:00', 'Europe/Stockholm')]);

    $this->artisan('statistics:backfill')->assertSuccessful();

    $day = StatRollup::query()->where(['metric' => 'watch.plays', 'period' => 'day'])->sole();

    expect($day->bucket->utc()->toDateTimeString())->toBe('2026-07-05 00:00:00')
        ->and($day->count)->toBe(1);
});
