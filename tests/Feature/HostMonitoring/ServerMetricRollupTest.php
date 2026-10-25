<?php

declare(strict_types=1);

use App\Models\Server;
use App\Models\ServerFilesystemSample;
use App\Models\ServerMetricSample;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;

function rollupMinuteSample(Server $server, string $at, float $cpu, int $memoryTotal = 8_589_934_592): ServerMetricSample
{
    return ServerMetricSample::factory()->for($server)->at(CarbonImmutable::parse($at))->create([
        'cpu_percent' => $cpu,
        'memory_total_bytes' => $memoryTotal,
    ]);
}

test('five-minute buckets average the minute samples', function (): void {
    $this->travelTo('2026-10-22 12:07:30');
    $server = Server::factory()->create();

    foreach ([10, 20, 30, 40, 50] as $minute => $cpu) {
        rollupMinuteSample($server, sprintf('2026-10-22 12:0%d:00', $minute), (float) $cpu, $minute === 2 ? 17_179_869_184 : 8_589_934_592);
    }

    $this->artisan('servers:rollup-metrics', ['--resolution' => 300])->assertSuccessful();

    $serverMetricSample = ServerMetricSample::query()->where('resolution', 300)->sole();

    expect($serverMetricSample->recorded_at)->toEqual(CarbonImmutable::parse('2026-10-22 12:00:00'))
        ->and($serverMetricSample->cpu_percent)->toBe(30.0)
        ->and($serverMetricSample->memory_total_bytes)->toBe(17_179_869_184)
        ->and($serverMetricSample->server_id)->toBe($server->id);
});

test('the open bucket is not rolled up yet', function (): void {
    $this->travelTo('2026-10-22 12:07:30');
    $server = Server::factory()->create();

    rollupMinuteSample($server, '2026-10-22 12:04:00', 10.0);
    rollupMinuteSample($server, '2026-10-22 12:05:00', 90.0);

    $this->artisan('servers:rollup-metrics', ['--resolution' => 300])->assertSuccessful();

    expect(ServerMetricSample::query()->where('resolution', 300)->pluck('recorded_at')->map->toDateTimeString()->all())
        ->toBe(['2026-10-22 12:00:00']);
});

test('re-running updates a bucket instead of duplicating it', function (): void {
    $this->travelTo('2026-10-22 12:07:30');
    $server = Server::factory()->create();

    rollupMinuteSample($server, '2026-10-22 12:01:00', 10.0);
    rollupMinuteSample($server, '2026-10-22 12:02:00', 20.0);

    $this->artisan('servers:rollup-metrics', ['--resolution' => 300])->assertSuccessful();
    expect(ServerMetricSample::query()->where('resolution', 300)->sole()->cpu_percent)->toBe(15.0);

    rollupMinuteSample($server, '2026-10-22 12:03:00', 60.0);

    $this->artisan('servers:rollup-metrics', ['--resolution' => 300])->assertSuccessful();

    expect(ServerMetricSample::query()->where('resolution', 300)->sole()->cpu_percent)->toBe(30.0);
});

test('buckets are kept per server', function (): void {
    $this->travelTo('2026-10-22 12:07:30');

    rollupMinuteSample(Server::factory()->create(), '2026-10-22 12:01:00', 10.0);
    rollupMinuteSample(Server::factory()->create(), '2026-10-22 12:01:00', 70.0);

    $this->artisan('servers:rollup-metrics', ['--resolution' => 300])->assertSuccessful();

    expect(ServerMetricSample::query()->where('resolution', 300)->orderBy('cpu_percent')->pluck('cpu_percent')->all())->toBe([10.0, 70.0]);
});

test('hourly buckets build from five-minute rows', function (): void {
    $this->travelTo('2026-10-22 12:07:30');
    $server = Server::factory()->create();

    foreach (range(0, 11) as $slot) {
        ServerMetricSample::factory()->for($server)->resolution(300)
            ->at(CarbonImmutable::parse('2026-10-22 11:00:00')->addMinutes($slot * 5))
            ->create(['cpu_percent' => $slot * 10.0]);
    }

    ServerMetricSample::factory()->for($server)->resolution(300)->at(CarbonImmutable::parse('2026-10-22 12:00:00'))->create(['cpu_percent' => 99.0]);

    $this->artisan('servers:rollup-metrics', ['--resolution' => 3600])->assertSuccessful();

    $serverMetricSample = ServerMetricSample::query()->where('resolution', 3600)->sole();

    expect($serverMetricSample->recorded_at)->toEqual(CarbonImmutable::parse('2026-10-22 11:00:00'))
        ->and($serverMetricSample->cpu_percent)->toBe(55.0);
});

test('filesystem buckets roll up per mount', function (): void {
    $this->travelTo('2026-10-22 12:07:30');
    $server = Server::factory()->create();

    foreach ([['/', 100, 900], ['/', 300, 700], ['/mnt/backups', 5000, 5000]] as $index => [$mount, $used, $available]) {
        ServerFilesystemSample::factory()->for($server)->mount($mount)
            ->at(CarbonImmutable::parse('2026-10-22 12:01:00')->addMinutes($index === 1 ? 1 : 0))
            ->bytes(1000 + ($mount === '/' ? 0 : 9000), $used, $available)
            ->create();
    }

    $this->artisan('servers:rollup-metrics', ['--resolution' => 300])->assertSuccessful();

    $rows = ServerFilesystemSample::query()->where('resolution', 300)->orderBy('mount')->get();

    expect($rows)->toHaveCount(2)
        ->and($rows[0]->mount)->toBe('/')
        ->and($rows[0]->used_bytes)->toBe(200)
        ->and($rows[0]->available_bytes)->toBe(800)
        ->and($rows[0]->total_bytes)->toBe(1000)
        ->and($rows[0]->fstype)->toBe('ext4')
        ->and($rows[1]->mount)->toBe('/mnt/backups')
        ->and($rows[1]->used_bytes)->toBe(5000)
        ->and($rows[1]->total_bytes)->toBe(10_000);
});

test('a mount missing from some minutes still rolls up from the minutes it has', function (): void {
    $this->travelTo('2026-10-22 12:07:30');
    $server = Server::factory()->create();

    ServerFilesystemSample::factory()->for($server)->at(CarbonImmutable::parse('2026-10-22 12:00:00'))->bytes(1000, 400, 600)->create();
    ServerFilesystemSample::factory()->for($server)->at(CarbonImmutable::parse('2026-10-22 12:04:00'))->bytes(1000, 600, 400)->create();
    ServerFilesystemSample::factory()->for($server)->mount('/mnt/flaky')->at(CarbonImmutable::parse('2026-10-22 12:04:00'))->create();

    $this->artisan('servers:rollup-metrics', ['--resolution' => 300])->assertSuccessful();

    expect(ServerFilesystemSample::query()->where('resolution', 300)->where('mount', '/')->sole()->used_bytes)->toBe(500)
        ->and(ServerFilesystemSample::query()->where('resolution', 300)->where('mount', '/mnt/flaky')->count())->toBe(1);
});

test('the rollup reports how many rows it wrote across both tables', function (): void {
    $this->travelTo('2026-10-22 12:07:30');
    $server = Server::factory()->create();

    rollupMinuteSample($server, '2026-10-22 12:01:00', 10.0);
    ServerFilesystemSample::factory()->for($server)->at(CarbonImmutable::parse('2026-10-22 12:01:00'))->create();

    $this->artisan('servers:rollup-metrics', ['--resolution' => 300])
        ->expectsOutputToContain('2 rollup row(s)')
        ->assertSuccessful();
});

test('samples older than the lookback are left alone', function (): void {
    $this->travelTo('2026-10-22 12:07:30');

    rollupMinuteSample(Server::factory()->create(), '2026-10-22 09:55:00', 10.0);

    $this->artisan('servers:rollup-metrics', ['--resolution' => 300])->assertSuccessful();

    expect(ServerMetricSample::query()->where('resolution', 300)->count())->toBe(0);
});

test('an unknown resolution fails', function (): void {
    $this->artisan('servers:rollup-metrics', ['--resolution' => 60])->assertFailed();
});

function scheduledRollup(string $needle): ?Event
{
    return collect(resolve(Schedule::class)->events())
        ->first(fn (Event $event): bool => str_contains((string) $event->command, $needle));
}

test('both rollups and the retention prune are scheduled', function (): void {
    $fiveMinutes = scheduledRollup('servers:rollup-metrics --resolution=300');
    $hourly = scheduledRollup('servers:rollup-metrics --resolution=3600');

    expect($fiveMinutes)->not->toBeNull()
        ->and($fiveMinutes->expression)->toBe('*/5 * * * *')
        ->and($hourly)->not->toBeNull()
        ->and($hourly->expression)->toBe('7 * * * *')
        ->and((string) scheduledRollup('model:prune')?->command)
        ->toContain(ServerMetricSample::class)
        ->toContain(ServerFilesystemSample::class);
});
