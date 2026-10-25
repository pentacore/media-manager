<?php

declare(strict_types=1);

use App\Enums\IncidentSeverity;
use App\Enums\IncidentStatus;
use App\Models\Server;
use App\Models\ServerIncident;
use App\Models\User;
use App\Notifications\HostIncidentEscalated;
use App\Notifications\HostIncidentOpened;
use App\Notifications\HostIncidentResolved;
use Illuminate\Support\Facades\Notification;
use Tests\Support\AgentReportFixtures;

/**
 * A responsive filesystem entry with the given byte counts.
 *
 * @return array<string, mixed>
 */
function diskRuleFilesystem(string $mount, int $usedBytes, int $availableBytes): array
{
    return [
        'mount' => $mount,
        'device' => '/dev/sdb1',
        'fstype' => 'xfs',
        'unresponsive' => false,
        'total_bytes' => $usedBytes + $availableBytes,
        'used_bytes' => $usedBytes,
        'available_bytes' => $availableBytes,
    ];
}

/**
 * Processes one report with exactly these filesystems and nothing else that could open an incident.
 *
 * @param  list<array<string, mixed>>  $filesystems
 */
function diskRuleReport(Server $server, array $filesystems): void
{
    AgentReportFixtures::dispatchReport($server, AgentReportFixtures::agentReportBody([
        'metrics.filesystems' => $filesystems,
        'metrics.failed_units' => [],
        'checks' => [],
    ]));
}

test('disk usage opens a warning at 85% and a critical at 95%', function (int $used, int $available, ?IncidentSeverity $expected): void {
    $server = Server::factory()->enrolled()->create();

    diskRuleReport($server, [diskRuleFilesystem('/', $used, $available)]);

    expect(ServerIncident::query()->open()->where('key', 'disk:/')->first()?->severity)->toBe($expected);
})->with([
    '84%' => [84, 16, null],
    '85%' => [85, 15, IncidentSeverity::Warning],
    '95%' => [95, 5, IncidentSeverity::Critical],
]);

test('the title, detail and context describe the mount', function (): void {
    $server = Server::factory()->enrolled()->create();
    $gibibyte = 1_073_741_824;

    diskRuleReport($server, [diskRuleFilesystem('/', 90 * $gibibyte, 10 * $gibibyte)]);

    expect(ServerIncident::query()->open()->sole())
        ->key->toBe('disk:/')
        ->severity->toBe(IncidentSeverity::Warning)
        ->title->toBe('Disk / is 90% full')
        ->detail->toBe('90 GB used of 100 GB, 10 GB free.')
        ->context->toEqual([
            'mount' => '/',
            'used_bytes' => 90 * $gibibyte,
            'available_bytes' => 10 * $gibibyte,
            'percent' => 90,
        ]);
});

test('the title rounds the percentage down', function (): void {
    $server = Server::factory()->enrolled()->create();

    diskRuleReport($server, [diskRuleFilesystem('/', 8_999, 1_001)]);

    expect(ServerIncident::query()->open()->sole()->title)->toBe('Disk / is 89% full');
});

test('a usage exactly at a configured threshold is judged without float error', function (): void {
    config(['mediamanager.monitoring.disk.warning_percent' => 57]);
    $server = Server::factory()->enrolled()->create();

    diskRuleReport($server, [diskRuleFilesystem('/', 57, 43)]);

    expect(ServerIncident::query()->open()->sole())
        ->severity->toBe(IncidentSeverity::Warning)
        ->title->toBe('Disk / is 57% full');
});

test('a report without filesystems leaves disk incidents untouched', function (): void {
    $server = Server::factory()->enrolled()->create();

    diskRuleReport($server, [diskRuleFilesystem('/mnt/data', 96, 4)]);
    diskRuleReport($server, []);
    diskRuleReport($server, []);

    expect(ServerIncident::query()->sole())->status->toBe(IncidentStatus::Open)->clear_streak->toBe(0);
});

test('an unresponsive mount opens a critical not-responding incident', function (): void {
    $server = Server::factory()->enrolled()->create();

    diskRuleReport($server, [AgentReportFixtures::agentFilesystem('/mnt/backups', unresponsive: true)]);

    expect(ServerIncident::query()->open()->sole())
        ->key->toBe('disk:/mnt/backups')
        ->severity->toBe(IncidentSeverity::Critical)
        ->title->toBe('Disk /mnt/backups is not responding')
        ->detail->toBe('The mount did not answer in time (a hung network mount?).')
        ->context->toEqual(['mount' => '/mnt/backups', 'fstype' => 'nfs4', 'unresponsive' => true]);
});

test('a mount that responds again resolves the not-responding incident after two reports', function (): void {
    $server = Server::factory()->enrolled()->create();

    diskRuleReport($server, [AgentReportFixtures::agentFilesystem('/mnt/backups', unresponsive: true)]);
    diskRuleReport($server, [diskRuleFilesystem('/mnt/backups', 40, 60)]);

    expect(ServerIncident::query()->sole())->status->toBe(IncidentStatus::Open)->clear_streak->toBe(1);

    diskRuleReport($server, [diskRuleFilesystem('/mnt/backups', 40, 60)]);

    expect(ServerIncident::query()->sole()->status)->toBe(IncidentStatus::Resolved);
});

test('a mount that responds again at 90% keeps the same incident as a warning', function (): void {
    $server = Server::factory()->enrolled()->create();

    diskRuleReport($server, [AgentReportFixtures::agentFilesystem('/mnt/backups', unresponsive: true)]);
    diskRuleReport($server, [diskRuleFilesystem('/mnt/backups', 90, 10)]);

    expect(ServerIncident::query()->sole())
        ->status->toBe(IncidentStatus::Open)
        ->severity->toBe(IncidentSeverity::Warning)
        ->title->toBe('Disk /mnt/backups is 90% full');
});

test('a zero-size filesystem raises nothing and does not throw', function (): void {
    $server = Server::factory()->enrolled()->create();

    diskRuleReport($server, [diskRuleFilesystem('/proc/fake', 0, 0)]);

    expect(ServerIncident::query()->exists())->toBeFalse();
});

test('a filesystem that reads zero-size keeps its open incident untouched', function (): void {
    $server = Server::factory()->enrolled()->create();

    diskRuleReport($server, [diskRuleFilesystem('/mnt/data', 96, 4)]);
    diskRuleReport($server, [diskRuleFilesystem('/mnt/data', 0, 0)]);
    diskRuleReport($server, [diskRuleFilesystem('/mnt/data', 0, 0)]);

    expect(ServerIncident::query()->sole())->status->toBe(IncidentStatus::Open)->clear_streak->toBe(0);
});

test('an unmounted filesystem resolves its disk incident after two reports', function (): void {
    $server = Server::factory()->enrolled()->create();

    diskRuleReport($server, [diskRuleFilesystem('/', 30, 70), diskRuleFilesystem('/mnt/data', 96, 4)]);
    diskRuleReport($server, [diskRuleFilesystem('/', 30, 70)]);

    expect(ServerIncident::query()->sole())->key->toBe('disk:/mnt/data')->status->toBe(IncidentStatus::Open);

    diskRuleReport($server, [diskRuleFilesystem('/', 30, 70)]);

    expect(ServerIncident::query()->sole()->status)->toBe(IncidentStatus::Resolved);
});

test('a very long mount path opens an incident', function (): void {
    $server = Server::factory()->enrolled()->create();
    $mount = '/'.str_repeat('a', 1000);

    diskRuleReport($server, [diskRuleFilesystem($mount, 99, 1)]);

    expect(ServerIncident::query()->open()->sole())
        ->key->toBe('disk:'.$mount)
        ->severity->toBe(IncidentSeverity::Critical)
        ->and(mb_strlen(ServerIncident::query()->sole()->title))->toBe(255);
});

test('disk usage wobbling around 85% keeps one incident open and pushes once', function (): void {
    Notification::fake();
    $admin = User::factory()->admin()->create();
    $server = Server::factory()->enrolled()->create();

    foreach ([86, 84, 84, 85, 83, 83, 86, 84, 84] as $percent) {
        diskRuleReport($server, [diskRuleFilesystem('/', $percent, 100 - $percent)]);
    }

    expect(ServerIncident::query()->sole())->status->toBe(IncidentStatus::Open);
    Notification::assertSentToTimes($admin, HostIncidentOpened::class, 1);
    Notification::assertNotSentTo($admin, HostIncidentResolved::class);
});

test('a disk incident resolves once usage is at least three points below the warning threshold', function (): void {
    $server = Server::factory()->enrolled()->create();

    diskRuleReport($server, [diskRuleFilesystem('/', 86, 14)]);
    diskRuleReport($server, [diskRuleFilesystem('/', 82, 18)]);
    diskRuleReport($server, [diskRuleFilesystem('/', 82, 18)]);

    expect(ServerIncident::query()->sole()->status)->toBe(IncidentStatus::Resolved);
});

test('a not-responding incident clears once the mount answers inside the hold band', function (): void {
    $server = Server::factory()->enrolled()->create();

    diskRuleReport($server, [AgentReportFixtures::agentFilesystem('/mnt/backups', unresponsive: true)]);
    diskRuleReport($server, [diskRuleFilesystem('/mnt/backups', 84, 16)]);

    expect(ServerIncident::query()->sole())->status->toBe(IncidentStatus::Open)->clear_streak->toBe(1);

    diskRuleReport($server, [diskRuleFilesystem('/mnt/backups', 84, 16)]);

    expect(ServerIncident::query()->sole()->status)->toBe(IncidentStatus::Resolved);
});

test('a critical usage incident steps down to a warning with a fresh title inside the hold band', function (): void {
    Notification::fake();
    $admin = User::factory()->admin()->create();
    $server = Server::factory()->enrolled()->create();

    diskRuleReport($server, [diskRuleFilesystem('/', 96, 4)]);
    diskRuleReport($server, [diskRuleFilesystem('/', 84, 16)]);

    expect(ServerIncident::query()->sole())
        ->status->toBe(IncidentStatus::Open)
        ->severity->toBe(IncidentSeverity::Warning)
        ->title->toBe('Disk / is 84% full')
        ->context->toMatchArray(['percent' => 84]);

    diskRuleReport($server, [diskRuleFilesystem('/', 83, 17)]);

    expect(ServerIncident::query()->sole())
        ->status->toBe(IncidentStatus::Open)
        ->severity->toBe(IncidentSeverity::Warning);
    Notification::assertSentToTimes($admin, HostIncidentOpened::class, 1);
    Notification::assertNotSentTo($admin, HostIncidentEscalated::class);

    diskRuleReport($server, [diskRuleFilesystem('/', 82, 18)]);
    diskRuleReport($server, [diskRuleFilesystem('/', 82, 18)]);

    expect(ServerIncident::query()->sole()->status)->toBe(IncidentStatus::Resolved);
});

test('usage just under the warning threshold opens nothing', function (): void {
    $server = Server::factory()->enrolled()->create();

    diskRuleReport($server, [diskRuleFilesystem('/', 84, 16)]);

    expect(ServerIncident::query()->exists())->toBeFalse();
});

test('ignored mounts open no disk incident', function (): void {
    config(['mediamanager.monitoring.disk.ignore_mounts' => ' /mnt/user , /mnt/disk*,']);
    $server = Server::factory()->enrolled()->create();

    diskRuleReport($server, [
        diskRuleFilesystem('/', 99, 1),
        diskRuleFilesystem('/mnt/user', 99, 1),
        diskRuleFilesystem('/mnt/disk1', 99, 1),
        diskRuleFilesystem('/mnt/disk12', 99, 1),
        diskRuleFilesystem('/mnt/disks/scratch', 99, 1),
    ]);

    expect(ServerIncident::query()->open()->orderBy('key')->pluck('key')->all())->toBe(['disk:/', 'disk:/mnt/disks/scratch']);
});

test('an open disk incident on a mount that becomes ignored resolves after two reports', function (): void {
    $server = Server::factory()->enrolled()->create();
    $filesystems = [diskRuleFilesystem('/mnt/disk1', 99, 1)];

    diskRuleReport($server, $filesystems);
    expect(ServerIncident::query()->open()->sole()->key)->toBe('disk:/mnt/disk1');

    config(['mediamanager.monitoring.disk.ignore_mounts' => '/mnt/disk*']);
    diskRuleReport($server, $filesystems);
    diskRuleReport($server, $filesystems);

    expect(ServerIncident::query()->sole()->status)->toBe(IncidentStatus::Resolved);
});
