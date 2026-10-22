<?php

declare(strict_types=1);

use App\Enums\HostCheckStatus;
use App\Enums\IncidentSeverity;
use App\Enums\IncidentStatus;
use App\Models\Server;
use App\Models\ServerCheck;
use App\Models\ServerIncident;
use App\Services\HostMonitoring\AgentReport;
use App\Services\HostMonitoring\Rules\CheckStatusRule;
use App\Services\HostMonitoring\Rules\IncidentObservation;
use Tests\Support\AgentReportFixtures;

/**
 * Processes one report that carries the given check results, a healthy disk and no failed units.
 *
 * @param  list<array<string, mixed>>  $checks
 */
function checkRuleReport(Server $server, array $checks): void
{
    AgentReportFixtures::dispatchReport($server, AgentReportFixtures::agentReportBody([
        'metrics.failed_units' => [],
        'checks' => $checks,
    ]));
}

test('a crit result opens a critical incident keyed by the check', function (): void {
    $server = Server::factory()->enrolled()->create();
    $check = ServerCheck::factory()->for($server)->mountPresent()->create();

    checkRuleReport($server, [
        AgentReportFixtures::agentCheckResult($check->uuid, HostCheckStatus::Crit, '/mnt/backups is not mounted', ['mounted' => false]),
    ]);

    expect(ServerIncident::query()->open()->sole())
        ->key->toBe('check:'.$check->uuid)
        ->severity->toBe(IncidentSeverity::Critical)
        ->title->toBe('Mount present: /mnt/backups')
        ->detail->toBe('/mnt/backups is not mounted')
        ->context->toEqual([
            'check_uuid' => $check->uuid,
            'type' => 'mount_present',
            'status' => 'crit',
            'observed' => ['mounted' => false],
        ]);
});

test('warn, unsupported and error open warnings with their own titles', function (HostCheckStatus $status, string $title): void {
    $server = Server::factory()->enrolled()->create();
    $check = ServerCheck::factory()->for($server)->mountPresent()->create();

    checkRuleReport($server, [AgentReportFixtures::agentCheckResult($check->uuid, $status, 'agent says so')]);

    expect(ServerIncident::query()->open()->sole())
        ->key->toBe('check:'.$check->uuid)
        ->severity->toBe(IncidentSeverity::Warning)
        ->title->toBe($title)
        ->detail->toBe('agent says so');
})->with([
    'warn' => [HostCheckStatus::Warn, 'Mount present: /mnt/backups'],
    'unsupported' => [HostCheckStatus::Unsupported, 'Mount present: /mnt/backups is not supported by this agent'],
    'error' => [HostCheckStatus::Error, 'Mount present: /mnt/backups could not run'],
]);

test('an ok result twice in a row resolves the check incident', function (): void {
    $server = Server::factory()->enrolled()->create();
    $check = ServerCheck::factory()->for($server)->create();

    checkRuleReport($server, [AgentReportFixtures::agentCheckResult($check->uuid, HostCheckStatus::Crit)]);
    checkRuleReport($server, [AgentReportFixtures::agentCheckResult($check->uuid, HostCheckStatus::Ok)]);

    expect(ServerIncident::query()->sole())->status->toBe(IncidentStatus::Open)->clear_streak->toBe(1);

    checkRuleReport($server, [AgentReportFixtures::agentCheckResult($check->uuid, HostCheckStatus::Ok)]);

    expect(ServerIncident::query()->sole()->status)->toBe(IncidentStatus::Resolved);
});

test('deleting a check resolves its open incident after two reports', function (): void {
    $server = Server::factory()->enrolled()->create();
    $check = ServerCheck::factory()->for($server)->create();

    checkRuleReport($server, [AgentReportFixtures::agentCheckResult($check->uuid, HostCheckStatus::Crit)]);
    $check->delete();
    checkRuleReport($server, []);

    expect(ServerIncident::query()->sole()->status)->toBe(IncidentStatus::Open);

    checkRuleReport($server, []);

    expect(ServerIncident::query()->sole()->status)->toBe(IncidentStatus::Resolved);
});

test('disabling a check resolves its open incident even while the agent still reports it', function (): void {
    $server = Server::factory()->enrolled()->create();
    $check = ServerCheck::factory()->for($server)->create();
    $crit = [AgentReportFixtures::agentCheckResult($check->uuid, HostCheckStatus::Crit)];

    checkRuleReport($server, $crit);
    $check->update(['is_enabled' => false]);
    checkRuleReport($server, $crit);
    checkRuleReport($server, $crit);

    expect(ServerIncident::query()->sole()->status)->toBe(IncidentStatus::Resolved);
});

test('an enabled check missing from the report keeps its incident open', function (): void {
    $server = Server::factory()->enrolled()->create();
    $check = ServerCheck::factory()->for($server)->create();

    checkRuleReport($server, [AgentReportFixtures::agentCheckResult($check->uuid, HostCheckStatus::Crit)]);
    checkRuleReport($server, []);
    checkRuleReport($server, []);
    checkRuleReport($server, []);

    expect(ServerIncident::query()->sole())->status->toBe(IncidentStatus::Open)->clear_streak->toBe(0);
});

test("another server's check result opens nothing", function (): void {
    $server = Server::factory()->enrolled()->create();
    $foreign = ServerCheck::factory()->create();

    checkRuleReport($server, [AgentReportFixtures::agentCheckResult($foreign->uuid, HostCheckStatus::Crit)]);

    expect(ServerIncident::query()->exists())->toBeFalse();
});

test('the rule owns check keys only', function (): void {
    $checkStatusRule = new CheckStatusRule;

    expect($checkStatusRule->ownsKey('check:01990000-0000-7000-8000-000000000001'))->toBeTrue()
        ->and($checkStatusRule->ownsKey('disk:/'))->toBeFalse()
        ->and($checkStatusRule->ownsKey('silent'))->toBeFalse();
});

test('evaluating holds an enabled check the report lacks and skips disabled ones', function (): void {
    $server = Server::factory()->enrolled()->create();
    $enabled = ServerCheck::factory()->for($server)->create();
    ServerCheck::factory()->for($server)->disabled()->create();

    $observations = new CheckStatusRule()->evaluate($server, AgentReport::fromPayload(
        AgentReportFixtures::agentReportBody(['checks' => []]),
        now(),
    ));

    expect($observations)->toEqual([IncidentObservation::hold('check:'.$enabled->uuid)]);
});
