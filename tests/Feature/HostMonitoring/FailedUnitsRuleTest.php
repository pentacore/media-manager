<?php

declare(strict_types=1);

use App\Enums\IncidentSeverity;
use App\Enums\IncidentStatus;
use App\Models\Server;
use App\Models\ServerIncident;
use Tests\Support\AgentReportFixtures;

/**
 * Processes one report whose only possible incident is the failed units one.
 *
 * @param  list<string>|null  $failedUnits
 */
function failedUnitsReport(Server $server, ?array $failedUnits): void
{
    AgentReportFixtures::dispatchReport($server, AgentReportFixtures::agentReportBody([
        'metrics.failed_units' => $failedUnits,
        'checks' => [],
    ]));
}

test('failed units open a warning listing them', function (): void {
    $server = Server::factory()->enrolled()->create();

    failedUnitsReport($server, ['backup.service', 'a.timer']);

    expect(ServerIncident::query()->open()->sole())
        ->key->toBe('failed_units')
        ->severity->toBe(IncidentSeverity::Warning)
        ->title->toBe('Failed systemd units')
        ->detail->toBe('a.timer, backup.service')
        ->context->toEqual(['units' => ['a.timer', 'backup.service']]);
});

test('an empty list resolves after two reports', function (): void {
    $server = Server::factory()->enrolled()->create();

    failedUnitsReport($server, ['backup.service']);
    failedUnitsReport($server, []);

    expect(ServerIncident::query()->sole())->status->toBe(IncidentStatus::Open)->clear_streak->toBe(1);

    failedUnitsReport($server, []);

    expect(ServerIncident::query()->sole()->status)->toBe(IncidentStatus::Resolved);
});

test('Unraid null failed units leave an open incident alone', function (): void {
    $server = Server::factory()->enrolled()->create();

    failedUnitsReport($server, ['backup.service']);
    failedUnitsReport($server, null);
    failedUnitsReport($server, null);
    failedUnitsReport($server, null);

    expect(ServerIncident::query()->sole())->status->toBe(IncidentStatus::Open)->clear_streak->toBe(0);
});
