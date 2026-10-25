<?php

declare(strict_types=1);

use App\Enums\HostCheckType;
use App\Enums\IncidentSeverity;
use App\Enums\NotificationSeverity;
use App\Enums\ServerMetricRange;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

uses(TestCase::class);

/**
 * @param  array<string, list<mixed>>  $rules
 * @return array<string, list<mixed>>
 */
function hostCheckParamRules(array $rules): array
{
    $prefixed = [];

    foreach ($rules as $name => $rule) {
        $prefixed[sprintf('params.%s', $name)] = $rule;
    }

    return $prefixed;
}

test('each check type validates the contract params', function (HostCheckType $type, array $valid, array $invalid): void {
    expect(Validator::make(['params' => $valid], hostCheckParamRules($type->paramRules()))->passes())->toBeTrue()
        ->and(Validator::make(['params' => $invalid], hostCheckParamRules($type->paramRules()))->passes())->toBeFalse();
})->with([
    'mount_present needs an absolute path' => [HostCheckType::MountPresent, ['path' => '/mnt/backups', 'fstype' => 'nfs4', 'source' => 'nas:/backups'], ['path' => 'mnt/backups']],
    'path_on_mount needs both paths' => [HostCheckType::PathOnMount, ['path' => '/mnt/backups/daily', 'mount' => '/mnt/backups'], ['path' => '/mnt/backups/daily']],
    'file_freshness needs crit >= warn' => [HostCheckType::FileFreshness, ['path' => '/backups', 'pattern' => '*.tar.gz', 'warn_hours' => 26, 'crit_hours' => 48], ['path' => '/backups', 'warn_hours' => 48, 'crit_hours' => 26]],
    'db_ping needs a known engine and a port' => [HostCheckType::DbPing, ['engine' => 'postgres', 'host' => '127.0.0.1', 'port' => 5432], ['engine' => 'redis', 'host' => 'db', 'port' => 70000]],
    'mountpoint_protected needs a path' => [HostCheckType::MountpointProtected, ['path' => '/mnt/backups'], []],
]);

test('the param keys are the bare rule names', function (): void {
    expect(HostCheckType::MountPresent->paramKeys())->toBe(['path', 'fstype', 'source'])
        ->and(HostCheckType::DbPing->paramKeys())->toBe(['engine', 'host', 'port', 'timeout_seconds']);
});

test('the subject is the path, or host and port for a database ping', function (): void {
    expect(HostCheckType::MountPresent->subject(['path' => '/mnt/backups']))->toBe('/mnt/backups')
        ->and(HostCheckType::DbPing->subject(['engine' => 'postgres', 'host' => '127.0.0.1', 'port' => 5432]))->toBe('127.0.0.1:5432');
});

test('each check type has a label', function (): void {
    expect(HostCheckType::MountPresent->label())->toBe('Mount present')
        ->and(HostCheckType::PathOnMount->label())->toBe('Path on mount')
        ->and(HostCheckType::FileFreshness->label())->toBe('File freshness')
        ->and(HostCheckType::DbPing->label())->toBe('Database ping')
        ->and(HostCheckType::MountpointProtected->label())->toBe('Mountpoint protected');
});

test('incident severity ranks and maps to a notification severity', function (): void {
    expect(IncidentSeverity::Warning->rank())->toBe(1)
        ->and(IncidentSeverity::Critical->rank())->toBe(2)
        ->and(IncidentSeverity::Warning->notificationSeverity())->toBe(NotificationSeverity::Warning)
        ->and(IncidentSeverity::Critical->notificationSeverity())->toBe(NotificationSeverity::Error);
});

test('chart ranges map to resolutions and at most 120 buckets', function (): void {
    expect(ServerMetricRange::SixHours->resolution())->toBe(60)->and(ServerMetricRange::SixHours->bucketSeconds())->toBe(180)
        ->and(ServerMetricRange::TwoDays->bucketSeconds())->toBe(1440)
        ->and(ServerMetricRange::ThirtyDays->resolution())->toBe(300)->and(ServerMetricRange::OneYear->resolution())->toBe(3600)
        ->and(ServerMetricRange::fromRequest('bogus'))->toBe(ServerMetricRange::SixHours)
        ->and(ServerMetricRange::fromRequest(null))->toBe(ServerMetricRange::SixHours)
        ->and(ServerMetricRange::fromRequest('30d'))->toBe(ServerMetricRange::ThirtyDays);
});

test('chart range options list every range', function (): void {
    expect(ServerMetricRange::options())->toBe([
        ['value' => '6h', 'label' => 'Last 6 hours'],
        ['value' => '48h', 'label' => 'Last 48 hours'],
        ['value' => '30d', 'label' => 'Last 30 days'],
        ['value' => '1y', 'label' => 'Last year'],
    ])->and(ServerMetricRange::TwoDays->seconds())->toBe(172_800);
});
