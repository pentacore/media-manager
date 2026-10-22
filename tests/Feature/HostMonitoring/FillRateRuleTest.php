<?php

declare(strict_types=1);

use App\Enums\IncidentSeverity;
use App\Enums\IncidentStatus;
use App\Models\Server;
use App\Models\ServerFilesystemSample;
use App\Models\ServerIncident;
use Carbon\CarbonImmutable;
use Tests\Support\AgentReportFixtures;

const FILL_RATE_GIB = 1_073_741_824;

const FILL_RATE_TOTAL = 100 * FILL_RATE_GIB;

/**
 * Seeds one 60 s sample per minute for the mount, the last one at `$endsAt`, with `available_bytes` falling
 * linearly by `$lossPerHour` to `$endAvailable` at that last minute.
 */
function fillRateSamples(Server $server, string $mount, int $count, int $endAvailable, int $lossPerHour, ?CarbonImmutable $endsAt = null): void
{
    $endsAt ??= now()->startOfMinute();

    for ($minutesBefore = $count - 1; $minutesBefore >= 0; $minutesBefore--) {
        $available = $endAvailable + intdiv($lossPerHour * $minutesBefore, 60);

        ServerFilesystemSample::factory()
            ->for($server)
            ->mount($mount)
            ->at($endsAt->subMinutes($minutesBefore))
            ->bytes(FILL_RATE_TOTAL, FILL_RATE_TOTAL - $available, $available)
            ->create();
    }
}

/**
 * A responsive filesystem entry of 100 GiB with the given bytes free.
 *
 * @return array<string, mixed>
 */
function fillRateFilesystem(string $mount, int $available): array
{
    return [
        'mount' => $mount,
        'device' => '/dev/sdb1',
        'fstype' => 'xfs',
        'unresponsive' => false,
        'total_bytes' => FILL_RATE_TOTAL,
        'used_bytes' => FILL_RATE_TOTAL - $available,
        'available_bytes' => $available,
    ];
}

/**
 * Processes one report with exactly these filesystems and nothing else that could open an incident.
 *
 * @param  list<array<string, mixed>>  $filesystems
 */
function fillRateReport(Server $server, array $filesystems): void
{
    AgentReportFixtures::dispatchReport($server, AgentReportFixtures::agentReportBody([
        'metrics.filesystems' => $filesystems,
        'metrics.failed_units' => [],
        'checks' => [],
    ]));
}

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-22 12:00:30'));
});

test('a mount losing 9 GB an hour with 18 GB left is critical at about 2 hours', function (): void {
    $server = Server::factory()->enrolled()->create();
    fillRateSamples($server, '/', 30, 18 * FILL_RATE_GIB, 9 * FILL_RATE_GIB);

    fillRateReport($server, [fillRateFilesystem('/', 18 * FILL_RATE_GIB)]);

    $serverIncident = ServerIncident::query()->open()->where('key', 'fill:/')->sole();

    expect($serverIncident)
        ->severity->toBe(IncidentSeverity::Critical)
        ->title->toBe('Disk / fills in 2 h')
        ->detail->toBe('~2 h until full (losing 9 GB/h).')
        ->and($serverIncident->context['mount'])->toBe('/')
        ->and($serverIncident->context['samples'])->toBe(30)
        ->and($serverIncident->context['hours_to_full'])->toEqualWithDelta(2.0, 0.01)
        ->and($serverIncident->context['slope_bytes_per_hour'])->toEqualWithDelta(-9 * FILL_RATE_GIB, FILL_RATE_GIB / 1000);
});

test('about 12 hours to full is a warning', function (): void {
    $server = Server::factory()->enrolled()->create();
    fillRateSamples($server, '/', 30, 24 * FILL_RATE_GIB, 2 * FILL_RATE_GIB);

    fillRateReport($server, [fillRateFilesystem('/', 24 * FILL_RATE_GIB)]);

    expect(ServerIncident::query()->open()->where('key', 'fill:/')->sole())
        ->severity->toBe(IncidentSeverity::Warning)
        ->title->toBe('Disk / fills in 12 h')
        ->detail->toBe('~12 h until full (losing 2 GB/h).');
});

test('under an hour the estimate is given in minutes', function (): void {
    $server = Server::factory()->enrolled()->create();
    fillRateSamples($server, '/', 30, 30 * FILL_RATE_GIB, 60 * FILL_RATE_GIB);

    fillRateReport($server, [fillRateFilesystem('/', 30 * FILL_RATE_GIB)]);

    expect(ServerIncident::query()->open()->where('key', 'fill:/')->sole())
        ->severity->toBe(IncidentSeverity::Critical)
        ->title->toBe('Disk / fills in 30 min')
        ->detail->toBe('~30 min until full (losing 60 GB/h).');
});

test('a filling disk with more than 24 hours left raises nothing', function (): void {
    $server = Server::factory()->enrolled()->create();
    fillRateSamples($server, '/', 30, 30 * FILL_RATE_GIB, FILL_RATE_GIB);

    fillRateReport($server, [fillRateFilesystem('/', 30 * FILL_RATE_GIB)]);

    expect(ServerIncident::query()->exists())->toBeFalse();
});

test('a shrinking or flat usage clears the fill incident', function (int $lossPerHour): void {
    $server = Server::factory()->enrolled()->create();
    $incident = ServerIncident::factory()->for($server)->key('fill:/')->create();
    fillRateSamples($server, '/', 30, 50 * FILL_RATE_GIB, $lossPerHour);

    fillRateReport($server, [fillRateFilesystem('/', 50 * FILL_RATE_GIB)]);

    expect($incident->fresh())->status->toBe(IncidentStatus::Open)->clear_streak->toBe(1);

    $this->travel(1)->minutes();
    fillRateReport($server, [fillRateFilesystem('/', 50 * FILL_RATE_GIB)]);

    expect($incident->fresh()->status)->toBe(IncidentStatus::Resolved);
})->with([
    'flat' => [0],
    'shrinking' => [-2 * FILL_RATE_GIB],
]);

test('an unresponsive mount keeps its fill incident untouched', function (): void {
    $server = Server::factory()->enrolled()->create();
    $incident = ServerIncident::factory()->for($server)->key('fill:/mnt/backups')->create();
    fillRateSamples($server, '/mnt/backups', 30, 50 * FILL_RATE_GIB, 0, now()->startOfMinute()->subMinute());

    foreach (range(1, 3) as $report) {
        fillRateReport($server, [AgentReportFixtures::agentFilesystem('/mnt/backups', unresponsive: true)]);
        $this->travel(1)->minutes();
    }

    expect($incident->fresh())->status->toBe(IncidentStatus::Open)->clear_streak->toBe(0);
});

test('fewer than 15 samples evaluates nothing', function (): void {
    $server = Server::factory()->enrolled()->create();
    $flatMountIncident = ServerIncident::factory()->for($server)->key('fill:/mnt/data')->create();
    fillRateSamples($server, '/', 14, 2 * FILL_RATE_GIB, 9 * FILL_RATE_GIB);
    fillRateSamples($server, '/mnt/data', 14, 50 * FILL_RATE_GIB, 0);

    fillRateReport($server, [fillRateFilesystem('/', 2 * FILL_RATE_GIB), fillRateFilesystem('/mnt/data', 50 * FILL_RATE_GIB)]);

    expect(ServerIncident::query()->where('key', 'fill:/')->exists())->toBeFalse()
        ->and($flatMountIncident->fresh())->status->toBe(IncidentStatus::Open)->clear_streak->toBe(0);
});

test('samples older than the 60-minute window are ignored', function (): void {
    $server = Server::factory()->enrolled()->create();
    fillRateSamples($server, '/', 30, 50 * FILL_RATE_GIB, 60 * FILL_RATE_GIB, now()->startOfMinute()->subMinutes(61));
    fillRateSamples($server, '/', 20, 20 * FILL_RATE_GIB, 0);

    fillRateReport($server, [fillRateFilesystem('/', 20 * FILL_RATE_GIB)]);

    expect(ServerIncident::query()->exists())->toBeFalse();
});

test('a report without filesystems leaves fill incidents untouched', function (): void {
    $server = Server::factory()->enrolled()->create();
    $incident = ServerIncident::factory()->for($server)->key('fill:/')->create();

    fillRateReport($server, []);
    $this->travel(1)->minutes();
    fillRateReport($server, []);

    expect($incident->fresh())->status->toBe(IncidentStatus::Open)->clear_streak->toBe(0);
});

test('an unmounted filesystem resolves its fill incident after two reports', function (): void {
    $server = Server::factory()->enrolled()->create();
    $incident = ServerIncident::factory()->for($server)->key('fill:/mnt/gone')->create();

    fillRateReport($server, [fillRateFilesystem('/', 50 * FILL_RATE_GIB)]);
    $this->travel(1)->minutes();
    fillRateReport($server, [fillRateFilesystem('/', 50 * FILL_RATE_GIB)]);

    expect($incident->fresh()->status)->toBe(IncidentStatus::Resolved);
});
