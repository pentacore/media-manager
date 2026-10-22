<?php

declare(strict_types=1);

use App\Enums\IncidentSeverity;
use App\Enums\IncidentStatus;
use App\Models\Server;
use App\Models\ServerIncident;
use Carbon\CarbonImmutable;
use Tests\Support\AgentReportFixtures;

/**
 * Processes one report with the given OOM kill counter and nothing else that could open an incident. The uptime is
 * counted from `$bootedAt` (a day before the tests' start unless given).
 */
function oomRuleReport(Server $server, int $oomKillsTotal, ?CarbonImmutable $bootedAt = null): void
{
    $bootedAt ??= CarbonImmutable::parse('2026-10-21 12:00:00');

    AgentReportFixtures::dispatchReport($server, AgentReportFixtures::agentReportBody([
        'host.uptime_seconds' => (int) $bootedAt->diffInSeconds(now()),
        'metrics.oom_kills_total' => $oomKillsTotal,
        'metrics.failed_units' => [],
        'checks' => [],
    ]));
}

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-22 12:00:00'));
});

test('the first report only records a baseline', function (): void {
    $server = Server::factory()->enrolled()->create();

    oomRuleReport($server, 5);

    expect(ServerIncident::query()->exists())->toBeFalse()
        ->and($server->fresh()->rule_state['oom'])->toEqual(['total' => 5, 'booted_at' => '2026-10-21T12:00:00+00:00', 'last_increase_at' => null, 'last_delta' => 0]);
});

test('the baseline report holds an open oom incident', function (): void {
    $server = Server::factory()->enrolled()->create();
    $incident = ServerIncident::factory()->for($server)->key('oom')->create(['clear_streak' => 1]);

    oomRuleReport($server, 5);

    expect($incident->fresh())->status->toBe(IncidentStatus::Open)->clear_streak->toBe(1);
});

test('an increase opens a warning that clears 30 minutes after the last kill', function (): void {
    $server = Server::factory()->enrolled()->create();

    oomRuleReport($server, 5);
    $this->travel(1)->minutes();
    oomRuleReport($server, 7);

    expect(ServerIncident::query()->open()->sole())
        ->key->toBe('oom')
        ->severity->toBe(IncidentSeverity::Warning)
        ->title->toBe('Out-of-memory kills')
        ->detail->toBe('The kernel killed 2 process(es) since the previous report (7 in total).');

    $this->travel(29)->minutes();
    oomRuleReport($server, 7);

    expect(ServerIncident::query()->sole())->status->toBe(IncidentStatus::Open)->clear_streak->toBe(0);

    $this->travel(2)->minutes();
    oomRuleReport($server, 7);
    $this->travel(1)->minutes();
    oomRuleReport($server, 7);

    expect(ServerIncident::query()->sole()->status)->toBe(IncidentStatus::Resolved);
});

test('a further kill restarts the 30 minutes and updates the count', function (): void {
    $server = Server::factory()->enrolled()->create();

    oomRuleReport($server, 5);
    $this->travel(1)->minutes();
    oomRuleReport($server, 7);
    $this->travel(20)->minutes();
    oomRuleReport($server, 8);
    $this->travel(20)->minutes();
    oomRuleReport($server, 8);

    expect(ServerIncident::query()->sole())
        ->status->toBe(IncidentStatus::Open)
        ->clear_streak->toBe(0)
        ->detail->toBe('The kernel killed 1 process(es) since the previous report (8 in total).');
});

test('a counter reset after a reboot opens nothing', function (): void {
    $server = Server::factory()->enrolled()->create();

    oomRuleReport($server, 7);
    $this->travel(1)->minutes();
    oomRuleReport($server, 0, bootedAt: now()->subSeconds(30));

    expect(ServerIncident::query()->exists())->toBeFalse()
        ->and($server->fresh()->rule_state['oom']['total'])->toBe(0);

    $this->travel(1)->minutes();
    oomRuleReport($server, 1, bootedAt: now()->subSeconds(90));

    expect(ServerIncident::query()->open()->sole()->detail)
        ->toBe('The kernel killed 1 process(es) since the previous report (1 in total).');
});

test('a counter that reaches the old total after a reboot opens nothing', function (): void {
    $server = Server::factory()->enrolled()->create();

    oomRuleReport($server, 5);
    $this->travel(10)->minutes();
    $rebootedAt = now()->subMinutes(2);
    oomRuleReport($server, 7, bootedAt: $rebootedAt);

    expect(ServerIncident::query()->exists())->toBeFalse()
        ->and($server->fresh()->rule_state['oom'])->toMatchArray(['total' => 7, 'booted_at' => $rebootedAt->toIso8601String()]);

    $this->travel(1)->minutes();
    oomRuleReport($server, 8, bootedAt: $rebootedAt);

    expect(ServerIncident::query()->open()->sole()->detail)
        ->toBe('The kernel killed 1 process(es) since the previous report (8 in total).');
});

test('a few seconds of jitter in the boot time is not a reboot', function (): void {
    $server = Server::factory()->enrolled()->create();

    oomRuleReport($server, 5);
    $this->travel(1)->minutes();
    oomRuleReport($server, 7, bootedAt: CarbonImmutable::parse('2026-10-21 12:00:20'));

    expect(ServerIncident::query()->open()->sole()->key)->toBe('oom');
});

test('without an uptime a lower counter still counts as a reboot', function (): void {
    $server = Server::factory()->enrolled()->create();

    AgentReportFixtures::dispatchReport($server, AgentReportFixtures::agentReportBody(['host.uptime_seconds' => null, 'metrics.oom_kills_total' => 7, 'metrics.failed_units' => [], 'checks' => []]));
    $this->travel(1)->minutes();
    AgentReportFixtures::dispatchReport($server, AgentReportFixtures::agentReportBody(['host.uptime_seconds' => null, 'metrics.oom_kills_total' => 0, 'metrics.failed_units' => [], 'checks' => []]));

    expect(ServerIncident::query()->exists())->toBeFalse()
        ->and($server->fresh()->rule_state['oom']['total'])->toBe(0);
});
