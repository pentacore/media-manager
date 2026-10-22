<?php

declare(strict_types=1);

use App\Enums\HostCheckStatus;
use App\Enums\IncidentSeverity;
use App\Enums\IncidentStatus;
use App\Models\Server;
use App\Models\ServerCheck;
use App\Models\ServerIncident;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Tests\Support\AgentReportFixtures;

/**
 * A report body whose root filesystem is used to the given percentage and that names no failed units or checks.
 *
 * @return array<string, mixed>
 */
function ruleEngineReportBody(int $usedPercent): array
{
    return AgentReportFixtures::agentReportBody([
        'metrics.filesystems' => [AgentReportFixtures::agentFilesystem('/', usedBytes: intdiv(107_374_182_400 * $usedPercent, 100))],
        'metrics.failed_units' => [],
        'checks' => [],
    ]);
}

test('a report clears the silent incident at once', function (): void {
    $server = Server::factory()->enrolled()->create();
    $silent = ServerIncident::factory()->for($server)->critical()->key('silent')->create();

    AgentReportFixtures::dispatchReport($server, ruleEngineReportBody(30));

    expect($silent->fresh()->status)->toBe(IncidentStatus::Resolved);
});

test('an older report runs no rules', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-22 12:06:00'));
    $server = Server::factory()->enrolled()->create();

    AgentReportFixtures::dispatchReport($server, ruleEngineReportBody(96), CarbonImmutable::parse('2026-10-22 12:05:00'));
    AgentReportFixtures::dispatchReport($server, ruleEngineReportBody(30), CarbonImmutable::parse('2026-10-22 12:04:00'));

    expect(ServerIncident::query()->sole())
        ->key->toBe('disk:/')
        ->status->toBe(IncidentStatus::Open)
        ->clear_streak->toBe(0);
});

test('an older report leaves the silent incident open', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-22 12:06:00'));
    $server = Server::factory()->enrolled()->create(['rule_state' => ['processed_at' => '2026-10-22T12:05:00+00:00']]);
    $silent = ServerIncident::factory()->for($server)->critical()->key('silent')->create();

    AgentReportFixtures::dispatchReport($server, ruleEngineReportBody(30), CarbonImmutable::parse('2026-10-22 12:04:00'));

    expect($silent->fresh()->status)->toBe(IncidentStatus::Open);
});

test('a processed_at beyond the clock tolerance does not stop the rules', function (): void {
    Log::spy();
    $this->travelTo(CarbonImmutable::parse('2026-10-22 12:00:00'));
    $server = Server::factory()->enrolled()->create(['rule_state' => ['processed_at' => '2026-10-22T13:00:00+00:00']]);

    AgentReportFixtures::dispatchReport($server, ruleEngineReportBody(96));

    expect(ServerIncident::query()->open()->sole()->key)->toBe('disk:/')
        ->and($server->fresh()->rule_state['processed_at'])->toBe(now()->toIso8601String());

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context): bool => str_contains($message, 'processed_at') && $context['server_id'] === $server->id)
        ->once();
});

test('a processed_at within the clock tolerance still marks an older report stale', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-22 12:00:00'));
    $server = Server::factory()->enrolled()->create(['rule_state' => ['processed_at' => '2026-10-22T12:04:00+00:00']]);

    AgentReportFixtures::dispatchReport($server, ruleEngineReportBody(96));

    expect(ServerIncident::query()->exists())->toBeFalse()
        ->and($server->fresh()->rule_state['processed_at'])->toBe('2026-10-22T12:04:00+00:00');
});

test('hold keeps the incident and its clear streak untouched', function (): void {
    $server = Server::factory()->enrolled()->create();
    $check = ServerCheck::factory()->for($server)->create();
    $incident = ServerIncident::factory()->for($server)->key($check->incidentKey())->create(['clear_streak' => 1, 'severity' => IncidentSeverity::Critical]);

    AgentReportFixtures::dispatchReport($server, ruleEngineReportBody(30));

    expect($incident->fresh())
        ->status->toBe(IncidentStatus::Open)
        ->clear_streak->toBe(1)
        ->severity->toBe(IncidentSeverity::Critical);
});

test('the sweep clears only incidents the rules own', function (): void {
    $server = Server::factory()->enrolled()->create();
    $vanishedDisk = ServerIncident::factory()->for($server)->key('disk:/mnt/gone')->create();
    $foreignKey = ServerIncident::factory()->for($server)->key('unowned:x')->create();
    $otherServer = ServerIncident::factory()->key('disk:/mnt/gone')->create();

    AgentReportFixtures::dispatchReport($server, ruleEngineReportBody(30));

    expect($vanishedDisk->fresh()->clear_streak)->toBe(1)
        ->and($foreignKey->fresh()->clear_streak)->toBe(0)
        ->and($otherServer->fresh()->clear_streak)->toBe(0);
});

test('one report opens incidents from every rule', function (): void {
    $server = Server::factory()->enrolled()->create();
    $check = ServerCheck::factory()->for($server)->create();

    AgentReportFixtures::dispatchReport($server, AgentReportFixtures::agentReportBody([
        'metrics.filesystems' => [AgentReportFixtures::agentFilesystem('/mnt/backups', unresponsive: true)],
        'metrics.failed_units' => ['backup.service'],
        'checks' => [AgentReportFixtures::agentCheckResult($check->uuid, HostCheckStatus::Warn)],
    ]));

    expect(ServerIncident::query()->open()->orderBy('key')->pluck('key')->all())
        ->toBe([$check->incidentKey(), 'disk:/mnt/backups', 'failed_units']);
});
