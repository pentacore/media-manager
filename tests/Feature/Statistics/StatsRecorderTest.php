<?php

declare(strict_types=1);

use App\Models\StatRollup;
use App\Services\Statistics\StatsRecorder;
use Carbon\CarbonImmutable;

beforeEach(function (): void {
    $this->recorder = resolve(StatsRecorder::class);
    $this->at = CarbonImmutable::parse('2026-07-06 14:23:00', 'UTC');
});

it('increments hour and day buckets additively', function (): void {
    $this->recorder->increment('webhooks.received', ['service' => 'sonarr'], $this->at);
    $this->recorder->increment('webhooks.received', ['service' => 'sonarr'], $this->at, 2);

    $hour = StatRollup::query()->where('period', 'hour')->sole();
    $day = StatRollup::query()->where('period', 'day')->sole();

    expect($hour->count)->toBe(3)
        ->and($hour->bucket->toIso8601String())->toBe('2026-07-06T14:00:00+00:00')
        ->and($day->count)->toBe(3)
        ->and($day->bucket->toIso8601String())->toBe('2026-07-06T00:00:00+00:00');
});

it('separates rows by dimensions and normalizes key order', function (): void {
    $this->recorder->increment('webhooks.received', ['service' => 'sonarr', 'event' => 'Grab'], $this->at);
    $this->recorder->increment('webhooks.received', ['event' => 'Grab', 'service' => 'sonarr'], $this->at);
    $this->recorder->increment('webhooks.received', ['service' => 'radarr', 'event' => 'Grab'], $this->at);

    expect(StatRollup::query()->where('period', 'hour')->count())->toBe(2)
        ->and(StatRollup::query()->where('period', 'hour')->get()->max('count'))->toBe(2);
});

it('maintains gauge count sum min max via sample', function (): void {
    $this->recorder->sample('queue.depth', ['service' => 'sabnzbd'], $this->at, 5.0);
    $this->recorder->sample('queue.depth', ['service' => 'sabnzbd'], $this->at, 11.0);
    $this->recorder->sample('queue.depth', ['service' => 'sabnzbd'], $this->at, 2.0);

    $hour = StatRollup::query()->where('period', 'hour')->sole();

    expect($hour->count)->toBe(3)
        ->and($hour->sum)->toBe(18.0)
        ->and($hour->min)->toBe(2.0)
        ->and($hour->max)->toBe(11.0);
});

it('put overwrites an existing bucket instead of adding', function (): void {
    $bucket = $this->at->startOfHour();
    $this->recorder->put('watch.plays', 'hour', $bucket, ['media_type' => 'Movie'], 4);
    $this->recorder->put('watch.plays', 'hour', $bucket, ['media_type' => 'Movie'], 7, 123.0);

    $row = StatRollup::query()->sole();

    expect($row->count)->toBe(7)->and($row->sum)->toBe(123.0);
});
