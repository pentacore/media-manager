<?php

declare(strict_types=1);

use App\Enums\ServerMetricRange;
use App\Models\Server;
use App\Models\ServerFilesystemSample;
use App\Models\ServerMetricSample;
use App\Services\HostMonitoring\ServerMetricRepository;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Sequence;

/**
 * The buckets that have a value, keyed by their ISO start.
 *
 * @param  list<array{at: string, value: ?float}>  $points
 * @return array<string, float>
 */
function serverMetricRepositoryValues(array $points): array
{
    return array_filter(array_column($points, 'value', 'at'), static fn (?float $value): bool => $value !== null);
}

test('the six hour range buckets minute samples into at most 120 points', function (): void {
    // Off a bucket boundary: a window of exactly now − 6 h would touch 121 three-minute buckets.
    $this->travelTo(CarbonImmutable::parse('2026-10-22 12:01:30'));
    $server = Server::factory()->create();
    ServerMetricSample::factory()->for($server)->count(360)
        ->sequence(fn (Sequence $sequence): array => [
            'recorded_at' => now()->startOfMinute()->subMinutes($sequence->index),
            'cpu_percent' => $sequence->index / 10,
        ])
        ->create();
    ServerMetricSample::factory()->at(now()->startOfMinute())->create(['cpu_percent' => 99]);

    $series = resolve(ServerMetricRepository::class)->series($server, ServerMetricRange::SixHours);

    // The window starts at 06:03, so 06:02 is left out; the first bucket averages 06:03, 06:04 and 06:05 (35.8, 35.7, 35.6).
    expect($series['cpu'])->toHaveCount(120)
        ->and($series['cpu'][0])->toBe(['at' => '2026-10-22T06:03:00+00:00', 'value' => 35.7])
        ->and($series['cpu'][119]['at'])->toBe('2026-10-22T12:00:00+00:00')
        ->and(serverMetricRepositoryValues($series['cpu']))->toHaveCount(120)
        ->and($series['memory'])->toHaveCount(120)
        ->and($series['memory'][0]['value'])->toBe(25.0);
});

test('the 30 day range reads five-minute rows only', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-22 12:00:00'));
    $server = Server::factory()->create();
    ServerMetricSample::factory()->for($server)->at(now()->subMinutes(10))->create(['cpu_percent' => 90]);
    ServerMetricSample::factory()->for($server)->resolution(ServerMetricSample::FIVE_MINUTES)->at(now()->subMinutes(10))->create(['cpu_percent' => 10]);
    ServerMetricSample::factory()->for($server)->resolution(ServerMetricSample::HOUR)->at(now()->subHours(2))->create(['cpu_percent' => 70]);
    ServerFilesystemSample::factory()->for($server)->at(now()->subMinutes(10))->bytes(100, 90, 10)->create();

    $series = resolve(ServerMetricRepository::class)->series($server, ServerMetricRange::ThirtyDays);

    expect($series['cpu'])->toHaveCount(120)
        ->and(serverMetricRepositoryValues($series['cpu']))->toBe(['2026-10-22T06:00:00+00:00' => 10.0])
        ->and($series['filesystems'])->toBe([]);
});

test('memory and filesystem points are percentages', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-22 12:00:00'));
    $server = Server::factory()->create();
    ServerMetricSample::factory()->for($server)->at(now()->subMinutes(5))->create([
        'memory_used_bytes' => 4 * 1024 ** 3,
        'memory_total_bytes' => 8 * 1024 ** 3,
    ]);
    ServerFilesystemSample::factory()->for($server)->mount('/srv')->at(now()->subMinutes(5))->bytes(120, 90, 10)->create();
    ServerFilesystemSample::factory()->for($server)->mount('/')->at(now()->subMinutes(5))->bytes(100, 25, 75)->create();

    $series = resolve(ServerMetricRepository::class)->series($server, ServerMetricRange::SixHours);

    expect(serverMetricRepositoryValues($series['memory']))->toBe(['2026-10-22T11:54:00+00:00' => 50.0])
        ->and(array_column($series['filesystems'], 'mount'))->toBe(['/', '/srv'])
        ->and(serverMetricRepositoryValues($series['filesystems'][0]['points']))->toBe(['2026-10-22T11:54:00+00:00' => 25.0])
        ->and(serverMetricRepositoryValues($series['filesystems'][1]['points']))->toBe(['2026-10-22T11:54:00+00:00' => 90.0])
        ->and($series['filesystems'][1]['points'])->toHaveCount(120);
});

test('a zero-size filesystem produces no points and no error', function (): void {
    $server = Server::factory()->create();
    ServerMetricSample::factory()->for($server)->at(now()->startOfMinute())->create(['memory_used_bytes' => 0, 'memory_total_bytes' => 0]);
    ServerFilesystemSample::factory()->for($server)->mount('/proc-like')->at(now()->startOfMinute())->bytes(0, 0, 0)->create();

    $series = resolve(ServerMetricRepository::class)->series($server, ServerMetricRange::SixHours);

    expect(serverMetricRepositoryValues($series['cpu']))->toHaveCount(1)
        ->and($series['memory'])->toBe([])
        ->and($series['filesystems'])->toBe([]);
});

test('buckets without samples stay in the series as gaps', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-22 12:01:30'));
    $server = Server::factory()->create();
    ServerMetricSample::factory()->for($server)->at(now()->setTime(11, 0))->create(['cpu_percent' => 30]);
    ServerMetricSample::factory()->for($server)->at(now()->setTime(12, 0))->create(['cpu_percent' => 60]);
    ServerFilesystemSample::factory()->for($server)->mount('/mnt/nas')->at(now()->setTime(9, 0))->bytes(100, 50, 50)->create();
    ServerFilesystemSample::factory()->for($server)->mount('/mnt/nas')->at(now()->setTime(12, 0))->bytes(100, 60, 40)->create();

    $series = resolve(ServerMetricRepository::class)->series($server, ServerMetricRange::SixHours);
    $byStart = array_column($series['cpu'], 'value', 'at');

    expect($series['cpu'])->toHaveCount(120)
        ->and($byStart['2026-10-22T11:00:00+00:00'])->toBe(30.0)
        ->and($byStart['2026-10-22T11:03:00+00:00'])->toBeNull()
        ->and($byStart['2026-10-22T11:57:00+00:00'])->toBeNull()
        ->and($byStart['2026-10-22T12:00:00+00:00'])->toBe(60.0)
        ->and(serverMetricRepositoryValues($series['cpu']))->toHaveCount(2)
        ->and($series['filesystems'][0]['points'])->toHaveCount(120)
        ->and(serverMetricRepositoryValues($series['filesystems'][0]['points']))->toBe([
            '2026-10-22T09:00:00+00:00' => 50.0,
            '2026-10-22T12:00:00+00:00' => 60.0,
        ]);
});

test('a new server has empty series for every range', function (ServerMetricRange $range): void {
    $server = Server::factory()->create();

    expect(resolve(ServerMetricRepository::class)->series($server, $range))
        ->toBe(['cpu' => [], 'memory' => [], 'filesystems' => []]);
})->with(ServerMetricRange::cases());
