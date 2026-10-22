<?php

declare(strict_types=1);

use App\Enums\IncidentSeverity;
use App\Enums\IncidentStatus;
use App\Models\Server;
use App\Models\ServerIncident;
use App\Models\ServerMetricSample;
use Carbon\CarbonImmutable;
use Tests\Support\AgentReportFixtures;

const MEMORY_RULE_TOTAL = 8_000_000_000;

/**
 * Seeds one 60 s host sample per minute before the current one, each with the given share of memory available.
 */
function memoryRuleSamples(Server $server, int $count, int $availablePercent): void
{
    for ($minutesBefore = $count; $minutesBefore >= 1; $minutesBefore--) {
        ServerMetricSample::factory()
            ->for($server)
            ->at(now()->startOfMinute()->subMinutes($minutesBefore))
            ->create([
                'memory_total_bytes' => MEMORY_RULE_TOTAL,
                'memory_used_bytes' => MEMORY_RULE_TOTAL - intdiv(MEMORY_RULE_TOTAL * $availablePercent, 100),
            ]);
    }
}

/**
 * Processes one report with the given share of memory available and nothing else that could open an incident.
 */
function memoryRuleReport(Server $server, int $availablePercent, int $totalBytes = MEMORY_RULE_TOTAL): void
{
    AgentReportFixtures::dispatchReport($server, AgentReportFixtures::agentReportBody([
        'metrics.memory.total_bytes' => $totalBytes,
        'metrics.memory.available_bytes' => intdiv($totalBytes * $availablePercent, 100),
        'metrics.failed_units' => [],
        'checks' => [],
    ]));
}

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-22 12:00:30'));
});

test('memory low for the whole window opens an incident by the configured thresholds', function (int $availablePercent, ?IncidentSeverity $expected): void {
    $server = Server::factory()->enrolled()->create();
    memoryRuleSamples($server, 4, $availablePercent);

    memoryRuleReport($server, $availablePercent);

    expect(ServerIncident::query()->open()->where('key', 'memory')->first()?->severity)->toBe($expected);
})->with([
    '10% available' => [10, null],
    '9% available' => [9, IncidentSeverity::Warning],
    '5% available' => [5, IncidentSeverity::Warning],
    '4% available' => [4, IncidentSeverity::Critical],
]);

test('the title, detail and context describe the latest reading', function (): void {
    $server = Server::factory()->enrolled()->create();
    memoryRuleSamples($server, 4, 9);

    memoryRuleReport($server, 8);

    expect(ServerIncident::query()->open()->sole())
        ->key->toBe('memory')
        ->severity->toBe(IncidentSeverity::Warning)
        ->title->toBe('Memory is low: 8% available')
        ->detail->toBe('610.4 MB available of 7.5 GB; below 10% for the last 5 minutes.')
        ->context->toEqual([
            'available_bytes' => intdiv(MEMORY_RULE_TOTAL * 8, 100),
            'total_bytes' => MEMORY_RULE_TOTAL,
            'available_percent' => 8,
            'samples' => 5,
        ]);
});

test('the least severe reading in the window decides the severity', function (): void {
    $server = Server::factory()->enrolled()->create();
    memoryRuleSamples($server, 4, 2);
    ServerMetricSample::query()->where('recorded_at', now()->startOfMinute()->subMinutes(4))->update(['memory_used_bytes' => MEMORY_RULE_TOTAL - intdiv(MEMORY_RULE_TOTAL * 7, 100)]);

    memoryRuleReport($server, 2);

    expect(ServerIncident::query()->open()->sole()->severity)->toBe(IncidentSeverity::Warning);
});

test('a short dip raises nothing', function (): void {
    $server = Server::factory()->enrolled()->create();
    memoryRuleSamples($server, 4, 50);

    memoryRuleReport($server, 2);

    expect(ServerIncident::query()->exists())->toBeFalse();
});

test('without enough history a low reading holds an open incident', function (): void {
    $server = Server::factory()->enrolled()->create();
    $incident = ServerIncident::factory()->for($server)->key('memory')->create(['clear_streak' => 1]);
    memoryRuleSamples($server, 2, 50);

    memoryRuleReport($server, 2);

    expect($incident->fresh())->status->toBe(IncidentStatus::Open)->clear_streak->toBe(1);
});

test('recovered memory resolves the incident after two reports', function (): void {
    $server = Server::factory()->enrolled()->create();
    $incident = ServerIncident::factory()->for($server)->key('memory')->create();

    memoryRuleReport($server, 50);
    $this->travel(1)->minutes();
    memoryRuleReport($server, 50);

    expect($incident->fresh()->status)->toBe(IncidentStatus::Resolved);
});

test('a report without a memory total leaves the memory incident untouched', function (): void {
    $server = Server::factory()->enrolled()->create();
    $incident = ServerIncident::factory()->for($server)->key('memory')->create();

    memoryRuleReport($server, 50, totalBytes: 0);
    $this->travel(1)->minutes();
    memoryRuleReport($server, 50, totalBytes: 0);

    expect($incident->fresh())->status->toBe(IncidentStatus::Open)->clear_streak->toBe(0);
});
