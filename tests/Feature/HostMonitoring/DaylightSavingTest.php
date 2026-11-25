<?php

declare(strict_types=1);

use App\Enums\ServerMetricRange;
use App\Models\Server;
use App\Models\ServerFilesystemSample;
use App\Models\ServerMetricSample;
use App\Services\HostMonitoring\ServerMetricRepository;
use Carbon\CarbonImmutable;
use Tests\Support\AgentReportFixtures;

// Europe/Stockholm falls back on 2026-10-25 (03:00 CEST becomes 02:00 CET, so 02:00–03:00 happens twice) and springs
// forward on 2026-03-29 (02:00 CET becomes 03:00 CEST, so 02:00–03:00 never happens).

function daylightSavingStockholm(): void
{
    config()->set('app.timezone', 'Europe/Stockholm');
    date_default_timezone_set('Europe/Stockholm');
}

/**
 * The stored instants of one resolution, oldest first, as UTC ISO strings.
 *
 * @param  class-string<ServerMetricSample|ServerFilesystemSample>  $model
 * @return list<string>
 */
function daylightSavingInstants(string $model, int $resolution): array
{
    return $model::query()->where('resolution', $resolution)->oldest('recorded_at')->get()
        ->map(fn (ServerMetricSample|ServerFilesystemSample $sample): string => $sample->recorded_at->utc()->toIso8601String())
        ->all();
}

test('both fall-back hours keep their own minute samples', function (): void {
    daylightSavingStockholm();
    $server = Server::factory()->enrolled()->create();

    // 02:30 CEST, then an hour later 02:30 CET: the same wall clock. Passed as instants, because travelTo() keeps only
    // the wall clock of an ambiguous time and lands both on CET.
    foreach (['2026-10-25 00:30:12', '2026-10-25 01:30:12'] as $utc) {
        AgentReportFixtures::dispatchReport($server, AgentReportFixtures::agentReportBody(), CarbonImmutable::parse($utc, 'UTC'));
    }

    expect(daylightSavingInstants(ServerMetricSample::class, ServerMetricSample::MINUTE))
        ->toBe(['2026-10-25T00:30:00+00:00', '2026-10-25T01:30:00+00:00'])
        ->and(daylightSavingInstants(ServerFilesystemSample::class, ServerMetricSample::MINUTE))
        ->toBe(['2026-10-25T00:30:00+00:00', '2026-10-25T01:30:00+00:00'])
        ->and(ServerMetricSample::query()->oldest('recorded_at')->get()->map(
            fn (ServerMetricSample $sample): string => $sample->recorded_at->toIso8601String(),
        )->all())->toBe(['2026-10-25T02:30:00+02:00', '2026-10-25T02:30:00+01:00']);
});

test('both fall-back hours roll up into their own five-minute and hourly buckets', function (): void {
    daylightSavingStockholm();
    $server = Server::factory()->create();

    foreach (['2026-10-25 00:30:00' => 10, '2026-10-25 00:31:00' => 20, '2026-10-25 01:30:00' => 70, '2026-10-25 01:31:00' => 90] as $utc => $cpu) {
        ServerMetricSample::factory()->for($server)->at(CarbonImmutable::parse($utc, 'UTC'))->create(['cpu_percent' => $cpu]);
        ServerFilesystemSample::factory()->for($server)->at(CarbonImmutable::parse($utc, 'UTC'))->bytes(1000, $cpu, 1000 - $cpu)->create();
    }

    // 02:37 CET, the second 02:xx hour (travelTo() resolves the ambiguous wall clock to CET).
    $this->travelTo(CarbonImmutable::parse('2026-10-25 01:37:30', 'UTC'));
    $this->artisan('servers:rollup-metrics', ['--resolution' => 300])->assertSuccessful();

    expect(ServerMetricSample::query()->where('resolution', 300)->oldest('recorded_at')->pluck('cpu_percent')->all())->toBe([15.0, 80.0])
        ->and(daylightSavingInstants(ServerMetricSample::class, 300))->toBe(['2026-10-25T00:30:00+00:00', '2026-10-25T01:30:00+00:00'])
        ->and(daylightSavingInstants(ServerFilesystemSample::class, 300))->toBe(['2026-10-25T00:30:00+00:00', '2026-10-25T01:30:00+00:00']);

    // 03:07 CET: both 02:xx hours are closed.
    $this->travelTo(CarbonImmutable::parse('2026-10-25 02:07:30', 'UTC'));
    $this->artisan('servers:rollup-metrics', ['--resolution' => 3600])->assertSuccessful();

    expect(ServerMetricSample::query()->where('resolution', 3600)->oldest('recorded_at')->pluck('cpu_percent')->all())->toBe([15.0, 80.0])
        ->and(daylightSavingInstants(ServerMetricSample::class, 3600))->toBe(['2026-10-25T00:00:00+00:00', '2026-10-25T01:00:00+00:00'])
        ->and(ServerFilesystemSample::query()->where('resolution', 3600)->oldest('recorded_at')->pluck('used_bytes')->all())->toBe([15, 80]);
});

test('the chart shows both fall-back hours as their own points', function (): void {
    daylightSavingStockholm();
    $server = Server::factory()->create();
    ServerMetricSample::factory()->for($server)->at(CarbonImmutable::parse('2026-10-25 00:30:00', 'UTC'))->create(['cpu_percent' => 10]);
    ServerMetricSample::factory()->for($server)->at(CarbonImmutable::parse('2026-10-25 01:30:00', 'UTC'))->create(['cpu_percent' => 70]);
    $this->travelTo(CarbonImmutable::parse('2026-10-25 02:01:30', 'UTC'));

    $cpu = resolve(ServerMetricRepository::class)->series($server, ServerMetricRange::SixHours)['cpu'];
    $values = array_filter(array_column($cpu, 'value', 'at'), static fn (?float $value): bool => $value !== null);

    expect($cpu)->toHaveCount(120)
        ->and(array_unique(array_column($cpu, 'at')))->toHaveCount(120)
        ->and(array_last($cpu)['at'])->toBe('2026-10-25T03:00:00+01:00')
        ->and($values)->toBe(['2026-10-25T02:30:00+02:00' => 10.0, '2026-10-25T02:30:00+01:00' => 70.0]);
});

test('the spring-forward gap rolls up and charts without a phantom hour', function (): void {
    daylightSavingStockholm();
    $server = Server::factory()->create();

    // 01:30 CET, then an hour later 03:30 CEST.
    foreach (['2026-03-29 00:30:00' => 10.0, '2026-03-29 01:30:00' => 70.0] as $utc => $cpu) {
        ServerMetricSample::factory()->for($server)->resolution(300)->at(CarbonImmutable::parse($utc, 'UTC'))->create(['cpu_percent' => $cpu]);
    }

    $this->travelTo(CarbonImmutable::parse('2026-03-29 02:07:30', 'UTC'));
    $this->artisan('servers:rollup-metrics', ['--resolution' => 3600])->assertSuccessful();

    expect(ServerMetricSample::query()->where('resolution', 3600)->oldest('recorded_at')->get()->map(
        fn (ServerMetricSample $sample): string => $sample->recorded_at->toIso8601String(),
    )->all())->toBe(['2026-03-29T01:00:00+01:00', '2026-03-29T03:00:00+02:00']);

    ServerMetricSample::factory()->for($server)->at(CarbonImmutable::parse('2026-03-29 02:00:00', 'UTC'))->create();
    $cpu = resolve(ServerMetricRepository::class)->series($server, ServerMetricRange::SixHours)['cpu'];

    expect(array_unique(array_column($cpu, 'at')))->toHaveCount(120)
        ->and(array_filter(array_column($cpu, 'at'), static fn (string $at): bool => str_starts_with($at, '2026-03-29T02:')))->toBe([]);
});

test('retention prunes by the stored instant on a non-UTC clock', function (): void {
    daylightSavingStockholm();
    config()->set('mediamanager.retention.server_metrics_minute_days', 2);
    $this->travelTo(CarbonImmutable::parse('2026-10-22 12:00:00', 'Europe/Stockholm'));
    $server = Server::factory()->create();
    ServerMetricSample::factory()->for($server)->at(now()->subDays(2)->addMinutes(30))->create();
    ServerMetricSample::factory()->for($server)->at(now()->subDays(2)->subMinutes(30))->create();

    $this->artisan('model:prune', ['--model' => [ServerMetricSample::class]])->assertSuccessful();

    expect(ServerMetricSample::query()->sole()->recorded_at->toIso8601String())->toBe('2026-10-20T12:30:00+02:00');
});
