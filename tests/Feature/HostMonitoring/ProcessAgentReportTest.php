<?php

declare(strict_types=1);

use App\Enums\HostCheckStatus;
use App\Events\ServerReported;
use App\Models\Server;
use App\Models\ServerCheck;
use App\Models\ServerFilesystemSample;
use App\Models\ServerMetricSample;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\Support\AgentReportFixtures;

test('processing stores a minute sample per host and filesystem', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-22 12:00:37'));
    $server = Server::factory()->enrolled()->create();

    AgentReportFixtures::dispatchReport($server, AgentReportFixtures::agentReportBody());

    expect(ServerMetricSample::query()->sole())
        ->server_id->toBe($server->id)
        ->resolution->toBe(ServerMetricSample::MINUTE)
        ->recorded_at->toEqual(CarbonImmutable::parse('2026-10-22 12:00:00'))
        ->cpu_percent->toBe(12.5)
        ->load1->toBe(0.5)
        ->memory_total_bytes->toBe(8_589_934_592)
        ->memory_used_bytes->toBe(8_589_934_592 - 6_442_450_944)
        ->swap_total_bytes->toBe(2_147_483_648)
        ->swap_used_bytes->toBe(2_147_483_648 - 1_073_741_824);

    expect(ServerFilesystemSample::query()->sole())
        ->server_id->toBe($server->id)
        ->resolution->toBe(ServerMetricSample::MINUTE)
        ->recorded_at->toEqual(CarbonImmutable::parse('2026-10-22 12:00:00'))
        ->mount->toBe('/')
        ->fstype->toBe('rootfs')
        ->total_bytes->toBe(107_374_182_400)
        ->used_bytes->toBe(32_212_254_720)
        ->available_bytes->toBe(75_161_927_680);
});

test('memory and swap use never go below zero', function (): void {
    $server = Server::factory()->enrolled()->create();

    AgentReportFixtures::dispatchReport($server, AgentReportFixtures::agentReportBody([
        'metrics.memory' => ['total_bytes' => 100, 'available_bytes' => 150, 'swap_total_bytes' => 0, 'swap_free_bytes' => 10],
    ]));

    expect(ServerMetricSample::query()->sole())
        ->memory_used_bytes->toBe(0)
        ->swap_used_bytes->toBe(0);
});

test('an unresponsive filesystem writes no sample row', function (): void {
    $server = Server::factory()->enrolled()->create();

    AgentReportFixtures::dispatchReport($server, AgentReportFixtures::agentReportBody([
        'metrics.filesystems' => [AgentReportFixtures::agentFilesystem('/'), AgentReportFixtures::agentFilesystem('/mnt/backups', unresponsive: true)],
    ]));

    expect(ServerFilesystemSample::query()->pluck('mount')->all())->toBe(['/']);
});

test('two reports in the same minute keep one sample with the latest values', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-22 12:00:05'));
    $server = Server::factory()->enrolled()->create();

    AgentReportFixtures::dispatchReport($server, AgentReportFixtures::agentReportBody(['metrics.cpu_percent' => 10]));
    $this->travelTo(CarbonImmutable::parse('2026-10-22 12:00:40'));
    AgentReportFixtures::dispatchReport($server, AgentReportFixtures::agentReportBody([
        'metrics.cpu_percent' => 20,
        'metrics.filesystems' => [AgentReportFixtures::agentFilesystem('/', usedBytes: 40_000_000_000)],
    ]));

    expect(ServerMetricSample::query()->sole()->cpu_percent)->toBe(20.0)
        ->and(ServerFilesystemSample::query()->sole()->used_bytes)->toBe(40_000_000_000);
});

test('check results are recorded on the matching checks only', function (): void {
    $this->freezeSecond();
    $server = Server::factory()->enrolled()->create();
    $own = ServerCheck::factory()->for($server)->mountPresent()->create();
    $foreign = ServerCheck::factory()->mountPresent()->create();

    AgentReportFixtures::dispatchReport($server, AgentReportFixtures::agentReportBody(['checks' => [
        AgentReportFixtures::agentCheckResult($own->uuid, HostCheckStatus::Crit, '/mnt/backups is not mounted', ['mounted' => false]),
        AgentReportFixtures::agentCheckResult($foreign->uuid, HostCheckStatus::Crit, 'not mine'),
        AgentReportFixtures::agentCheckResult((string) Str::uuid(), HostCheckStatus::Crit),
    ]]));

    $own->refresh();
    $foreign->refresh();

    expect($own->last_status)->toBe(HostCheckStatus::Crit)
        ->and($own->last_message)->toBe('/mnt/backups is not mounted')
        ->and($own->last_observed)->toBe(['mounted' => false])
        ->and($own->last_evaluated_at?->equalTo(now()))->toBeTrue()
        ->and($foreign->last_status)->toBeNull()
        ->and($foreign->last_message)->toBeNull()
        ->and($foreign->last_evaluated_at)->toBeNull();
});

test('a long check message is cut to 500 characters', function (): void {
    $server = Server::factory()->enrolled()->create();
    $check = ServerCheck::factory()->for($server)->create();

    AgentReportFixtures::dispatchReport($server, AgentReportFixtures::agentReportBody(['checks' => [
        AgentReportFixtures::agentCheckResult($check->uuid, HostCheckStatus::Error, str_repeat('x', 2000)),
    ]]));

    expect($check->fresh()->last_message)->toBe(str_repeat('x', 500));
});

test('recording a check result does not bump the config version', function (): void {
    $server = Server::factory()->enrolled()->create();
    $check = ServerCheck::factory()->for($server)->create();
    $configVersion = $server->fresh()->config_version;

    AgentReportFixtures::dispatchReport($server, AgentReportFixtures::agentReportBody(['checks' => [
        AgentReportFixtures::agentCheckResult($check->uuid, HostCheckStatus::Ok),
    ]]));

    expect($server->fresh()->config_version)->toBe($configVersion)
        ->and($check->fresh()->last_status)->toBe(HostCheckStatus::Ok);
});

test('two in-order reports on the same day both record their check results', function (): void {
    $server = Server::factory()->enrolled()->create();
    $check = ServerCheck::factory()->for($server)->create();

    AgentReportFixtures::dispatchReport($server, AgentReportFixtures::agentReportBody(['checks' => [
        AgentReportFixtures::agentCheckResult($check->uuid, HostCheckStatus::Warn),
    ]]), CarbonImmutable::parse('2026-10-22 12:04:00'));
    AgentReportFixtures::dispatchReport($server, AgentReportFixtures::agentReportBody(['checks' => [
        AgentReportFixtures::agentCheckResult($check->uuid, HostCheckStatus::Crit),
    ]]), CarbonImmutable::parse('2026-10-22 12:05:00'));

    expect($check->fresh()->last_status)->toBe(HostCheckStatus::Crit)
        ->and($server->fresh()->rule_state['processed_at'])->toBe(CarbonImmutable::parse('2026-10-22 12:05:00')->toIso8601String());
});

test('an older report processed after a newer one stores samples but changes no check result', function (): void {
    $server = Server::factory()->enrolled()->create();
    $check = ServerCheck::factory()->for($server)->create();

    AgentReportFixtures::dispatchReport($server, AgentReportFixtures::agentReportBody(['checks' => [
        AgentReportFixtures::agentCheckResult($check->uuid, HostCheckStatus::Ok),
    ]]), CarbonImmutable::parse('2026-10-22 12:05:00'));
    AgentReportFixtures::dispatchReport($server, AgentReportFixtures::agentReportBody(['checks' => [
        AgentReportFixtures::agentCheckResult($check->uuid, HostCheckStatus::Crit),
    ]]), CarbonImmutable::parse('2026-10-22 12:04:00'));

    expect($check->fresh()->last_status)->toBe(HostCheckStatus::Ok)
        ->and($server->fresh()->rule_state['processed_at'])->toBe(CarbonImmutable::parse('2026-10-22 12:05:00')->toIso8601String())
        ->and(ServerMetricSample::query()->where('recorded_at', CarbonImmutable::parse('2026-10-22 12:04:00'))->exists())->toBeTrue();
});

test('a report whose sent_at is a day off is filed under the receive time', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-22 12:00:37'));
    $server = Server::factory()->enrolled()->create();

    AgentReportFixtures::dispatchReport($server, AgentReportFixtures::agentReportBody(['sent_at' => now()->subDay()->toIso8601String()]));

    expect(ServerMetricSample::query()->sole()->recorded_at)->toEqual(CarbonImmutable::parse('2026-10-22 12:00:00'));
});

test('a job for a deleted server does nothing', function (): void {
    $server = Server::factory()->enrolled()->create();
    $server->delete();

    AgentReportFixtures::dispatchReport($server, AgentReportFixtures::agentReportBody());

    expect(ServerMetricSample::query()->count())->toBe(0)
        ->and(ServerFilesystemSample::query()->count())->toBe(0);
});

test('processing broadcasts ServerReported once with the server and its last report time', function (): void {
    Event::fake([ServerReported::class]);
    $this->freezeSecond();
    $server = Server::factory()->enrolled()->create(['last_report_at' => now()]);

    AgentReportFixtures::dispatchReport($server, AgentReportFixtures::agentReportBody());

    Event::assertDispatchedTimes(ServerReported::class, 1);
    Event::assertDispatched(fn (ServerReported $event): bool => $event->broadcastWith() === [
        'server_id' => $server->id,
        'last_report_at' => now()->toIso8601String(),
    ]);
});
