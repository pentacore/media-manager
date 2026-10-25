<?php

declare(strict_types=1);

use App\Models\Server;
use App\Models\ServerFilesystemSample;
use App\Models\ServerMetricSample;

/**
 * Each resolution twice, once just inside its window and once just outside it.
 *
 * @return list<array{int, int}> resolution and age in hours
 */
function retentionRows(): array
{
    return [
        [ServerMetricSample::MINUTE, 47],
        [ServerMetricSample::MINUTE, 49],
        [ServerMetricSample::FIVE_MINUTES, 29 * 24],
        [ServerMetricSample::FIVE_MINUTES, 31 * 24],
        [ServerMetricSample::HOUR, 364 * 24],
        [ServerMetricSample::HOUR, 366 * 24],
    ];
}

function seedRetentionSamples(Server $server): void
{
    foreach (retentionRows() as [$resolution, $hours]) {
        ServerMetricSample::factory()->for($server)->resolution($resolution)->at(now()->subHours($hours))->create();
        ServerFilesystemSample::factory()->for($server)->resolution($resolution)->at(now()->subHours($hours))->create();
    }
}

test('pruning keeps each resolution for its own window', function (): void {
    $this->freezeTime();
    seedRetentionSamples(Server::factory()->create());

    $this->artisan('model:prune', ['--model' => [ServerMetricSample::class, ServerFilesystemSample::class]])->assertSuccessful();

    expect(ServerMetricSample::query()->count())->toBe(3)
        ->and(ServerFilesystemSample::query()->count())->toBe(3)
        ->and(ServerMetricSample::query()->orderBy('resolution')->oldest('recorded_at')->get()->map(fn (ServerMetricSample $sample): int => $sample->resolution)->all())
        ->toBe([60, 300, 3600]);
});

test('a zero retention keeps that resolution forever', function (): void {
    $this->freezeTime();
    config()->set('mediamanager.retention.server_metrics_minute_days', 0);
    seedRetentionSamples(Server::factory()->create());

    $this->artisan('model:prune', ['--model' => [ServerMetricSample::class, ServerFilesystemSample::class]])->assertSuccessful();

    expect(ServerMetricSample::query()->where('resolution', ServerMetricSample::MINUTE)->count())->toBe(2)
        ->and(ServerFilesystemSample::query()->where('resolution', ServerMetricSample::MINUTE)->count())->toBe(2)
        ->and(ServerMetricSample::query()->count())->toBe(4);
});

test('the retention window per resolution comes from config', function (): void {
    expect(ServerMetricSample::retentionDays(ServerMetricSample::MINUTE))->toBe(2)
        ->and(ServerMetricSample::retentionDays(ServerMetricSample::FIVE_MINUTES))->toBe(30)
        ->and(ServerMetricSample::retentionDays(ServerMetricSample::HOUR))->toBe(365);
});
