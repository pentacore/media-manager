<?php

declare(strict_types=1);

use App\Enums\IncidentSeverity;
use App\Enums\IncidentStatus;
use App\Models\Server;
use App\Models\ServerIncident;
use Carbon\CarbonImmutable;
use Tests\Support\AgentReportFixtures;

/**
 * @return array{name: string, state: string, health: ?string, restart_count: int}
 */
function restartRuleContainer(int $restartCount, string $name = 'postfix-mailcow', string $state = 'restarting'): array
{
    return ['name' => $name, 'state' => $state, 'health' => null, 'restart_count' => $restartCount];
}

/**
 * Processes one report with these containers (null = Docker unavailable) and nothing else that could open an incident.
 *
 * @param  list<array<string, mixed>>|null  $containers
 */
function restartRuleReport(Server $server, ?array $containers): void
{
    AgentReportFixtures::dispatchReport($server, AgentReportFixtures::agentReportBody([
        'metrics.containers' => $containers,
        'metrics.failed_units' => [],
        'checks' => [],
    ]));
}

/**
 * Reports the given restart counts for postfix-mailcow two minutes apart.
 *
 * @param  list<int>  $restartCounts
 */
function restartRuleReports(Server $server, array $restartCounts): void
{
    foreach ($restartCounts as $index => $restartCount) {
        if ($index > 0) {
            test()->travel(2)->minutes();
        }

        restartRuleReport($server, [restartRuleContainer($restartCount)]);
    }
}

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-22 12:00:00'));
});

test('three restarts within 15 minutes open a critical restart loop', function (): void {
    $server = Server::factory()->enrolled()->create();

    restartRuleReports($server, [4, 5, 6]);

    expect(ServerIncident::query()->exists())->toBeFalse();

    $this->travel(2)->minutes();
    restartRuleReport($server, [restartRuleContainer(7)]);

    expect(ServerIncident::query()->open()->sole())
        ->key->toBe('restarts:postfix-mailcow')
        ->severity->toBe(IncidentSeverity::Critical)
        ->title->toBe('Container postfix-mailcow is restart-looping')
        ->detail->toBe('3 restarts in the last 15 minutes (state: restarting).')
        ->context->toEqual(['name' => 'postfix-mailcow', 'state' => 'restarting', 'health' => null, 'restart_count' => 7, 'increase' => 3]);
});

test('restarts spread wider than the window raise nothing', function (): void {
    $server = Server::factory()->enrolled()->create();

    foreach ([4, 5, 6, 7] as $index => $restartCount) {
        if ($index > 0) {
            $this->travel(8)->minutes();
        }

        restartRuleReport($server, [restartRuleContainer($restartCount)]);
    }

    expect(ServerIncident::query()->exists())->toBeFalse();
});

test('the window comes from the configuration', function (): void {
    config(['mediamanager.monitoring.restarts.window_minutes' => 30]);
    $server = Server::factory()->enrolled()->create();

    foreach ([4, 5, 6, 7] as $index => $restartCount) {
        if ($index > 0) {
            $this->travel(8)->minutes();
        }

        restartRuleReport($server, [restartRuleContainer($restartCount)]);
    }

    expect(ServerIncident::query()->open()->sole()->detail)->toBe('3 restarts in the last 30 minutes (state: restarting).');
});

test('a loop resolves after 15 minutes without a restart', function (): void {
    $server = Server::factory()->enrolled()->create();
    restartRuleReports($server, [4, 5, 6, 7]);

    $this->travel(16)->minutes();
    restartRuleReport($server, [restartRuleContainer(7, state: 'running')]);
    $this->travel(1)->minutes();
    restartRuleReport($server, [restartRuleContainer(7, state: 'running')]);

    expect(ServerIncident::query()->sole()->status)->toBe(IncidentStatus::Resolved);
});

test('a recreated container with a lower count starts over', function (): void {
    $server = Server::factory()->enrolled()->create();

    restartRuleReports($server, [10, 0, 1, 2]);

    expect(ServerIncident::query()->exists())->toBeFalse()
        ->and(array_column($server->fresh()->rule_state['restarts']['postfix-mailcow'], 1))->toBe([0, 1, 2]);
});

test('a removed container resolves its incident after two reports', function (): void {
    $server = Server::factory()->enrolled()->create();
    restartRuleReports($server, [4, 5, 6, 7]);

    $this->travel(1)->minutes();
    restartRuleReport($server, [restartRuleContainer(1, name: 'nginx', state: 'running')]);
    $this->travel(1)->minutes();
    restartRuleReport($server, [restartRuleContainer(1, name: 'nginx', state: 'running')]);

    expect(ServerIncident::query()->sole())->key->toBe('restarts:postfix-mailcow')->status->toBe(IncidentStatus::Resolved)
        ->and(array_keys($server->fresh()->rule_state['restarts']))->toBe(['nginx']);
});

test('docker unavailable leaves restart incidents untouched', function (): void {
    $server = Server::factory()->enrolled()->create();
    restartRuleReports($server, [4, 5, 6, 7]);

    foreach (range(1, 3) as $report) {
        $this->travel(1)->minutes();
        restartRuleReport($server, null);
    }

    expect(ServerIncident::query()->sole())->status->toBe(IncidentStatus::Open)->clear_streak->toBe(0)
        ->and(array_column($server->fresh()->rule_state['restarts']['postfix-mailcow'], 1))->toBe([4, 5, 6, 7]);
});
