<?php

declare(strict_types=1);

use App\Enums\HostCheckType;
use App\Models\ActivityLog;
use App\Models\Server;
use App\Models\ServerCheck;
use App\Models\User;

/**
 * jsonb stores object keys in its own order and a whole float as an integer, so params are compared key-sorted.
 *
 * @param  ServerCheck|array<string, mixed>|null  $serverCheck
 * @return array<string, mixed>
 */
function sortedServerCheckParams(ServerCheck|array|null $serverCheck): array
{
    $params = is_array($serverCheck) ? $serverCheck : $serverCheck?->params ?? [];
    ksort($params);

    return $params;
}

test('an admin adds a mount check and the config version moves', function (): void {
    $server = Server::factory()->create(['name' => 'babel']);
    $this->actingAs(User::factory()->admin()->create());

    $this->from(route('admin.servers.show', $server))
        ->post(route('admin.servers.checks.store', $server), [
            'type' => 'mount_present',
            'is_enabled' => true,
            'params' => ['path' => '/mnt/backups', 'fstype' => 'nfs4', 'source' => '', 'junk' => 'x'],
        ])
        ->assertRedirect(route('admin.servers.show', $server))
        ->assertSessionHas('inertia.flash_data.toast.message', 'Check added.');

    $serverCheck = ServerCheck::query()->sole();

    expect(sortedServerCheckParams($serverCheck))->toBe(['fstype' => 'nfs4', 'path' => '/mnt/backups'])
        ->and($serverCheck->server_id)->toBe($server->id)
        ->and($serverCheck->type)->toBe(HostCheckType::MountPresent)
        ->and($serverCheck->is_enabled)->toBeTrue()
        ->and($server->fresh()?->config_version)->toBe(2);

    $activityLog = ActivityLog::query()->where('action', 'server_check.created')->sole();

    expect($activityLog->description)->toBe('Added check "Mount present: /mnt/backups" on server "babel".')
        ->and($activityLog->subject_id)->toBe($serverCheck->id)
        ->and($activityLog->metadata['context'])->toBe(['server_id' => $server->id]);
});

test('numeric params are stored as json numbers, not strings', function (): void {
    $server = Server::factory()->create();
    $this->actingAs(User::factory()->admin()->create());

    $this->post(route('admin.servers.checks.store', $server), [
        'type' => 'db_ping',
        'params' => ['engine' => 'postgres', 'host' => 'db.lan', 'port' => '5432', 'timeout_seconds' => '3'],
    ])->assertSessionHasNoErrors();

    $this->post(route('admin.servers.checks.store', $server), [
        'type' => 'file_freshness',
        'params' => ['path' => '/mnt/backups', 'pattern' => '*.tar.gz', 'warn_hours' => '26', 'crit_hours' => '48.5'],
    ])->assertSessionHasNoErrors();

    [$dbPing, $fileFreshness] = ServerCheck::query()->orderBy('id')->get()->all();

    expect(sortedServerCheckParams($dbPing))->toBe(['engine' => 'postgres', 'host' => 'db.lan', 'port' => 5432, 'timeout_seconds' => 3])
        ->and($dbPing->is_enabled)->toBeTrue()
        ->and(sortedServerCheckParams($fileFreshness))->toBe(['crit_hours' => 48.5, 'path' => '/mnt/backups', 'pattern' => '*.tar.gz', 'warn_hours' => 26]);
});

test('params are validated per type', function (string $type, array $params, string $errorKey): void {
    $server = Server::factory()->create();

    $this->actingAs(User::factory()->admin()->create())
        ->post(route('admin.servers.checks.store', $server), ['type' => $type, 'is_enabled' => true, 'params' => $params])
        ->assertSessionHasErrors($errorKey);

    expect(ServerCheck::query()->count())->toBe(0)
        ->and($server->fresh()?->config_version)->toBe(1);
})->with([
    'relative path' => ['mount_present', ['path' => 'relative'], 'params.path'],
    'crit below warn' => ['file_freshness', ['path' => '/b', 'warn_hours' => 48, 'crit_hours' => 24], 'params.crit_hours'],
    'unknown engine' => ['db_ping', ['engine' => 'redis', 'host' => 'db', 'port' => 6379], 'params.engine'],
    'port zero' => ['db_ping', ['engine' => 'mysql', 'host' => 'db', 'port' => 0], 'params.port'],
    'port too high' => ['db_ping', ['engine' => 'mysql', 'host' => 'db', 'port' => 65536], 'params.port'],
    'host with a space' => ['db_ping', ['engine' => 'mysql', 'host' => 'db; rm -rf /', 'port' => 3306], 'params.host'],
    'host with a scheme' => ['db_ping', ['engine' => 'mysql', 'host' => 'tcp://db', 'port' => 3306], 'params.host'],
    'host starting with a hyphen' => ['db_ping', ['engine' => 'mysql', 'host' => '-db', 'port' => 3306], 'params.host'],
    'path with a newline' => ['mountpoint_protected', ['path' => "/mnt/backups\n/etc"], 'params.path'],
    'path with a nul byte' => ['path_on_mount', ['path' => "/mnt/a\0b", 'mount' => '/mnt'], 'params.path'],
    'path too long' => ['mount_present', ['path' => '/'.str_repeat('a', 1024)], 'params.path'],
    'source with a newline' => ['mount_present', ['path' => '/mnt/backups', 'source' => "nas:/backups\nx"], 'params.source'],
    'pattern with a slash' => ['file_freshness', ['path' => '/b', 'pattern' => '../*.sql', 'warn_hours' => 1, 'crit_hours' => 2], 'params.pattern'],
    'pattern with a newline' => ['file_freshness', ['path' => '/b', 'pattern' => "*.sql\n*.gz", 'warn_hours' => 1, 'crit_hours' => 2], 'params.pattern'],
    'path with a unicode line separator' => ['mountpoint_protected', ['path' => "/mnt/backups\u{2028}x"], 'params.path'],
    'path with a next-line control' => ['mountpoint_protected', ['path' => "/mnt/backups\u{85}x"], 'params.path'],
    'path with invalid utf-8' => ['mountpoint_protected', ['path' => "/mnt/\xFFbackups"], 'params.path'],
    'source with invalid utf-8' => ['mount_present', ['path' => '/mnt/backups', 'source' => "nas:/\xC3("], 'params.source'],
    'pattern with a unicode paragraph separator' => ['file_freshness', ['path' => '/b', 'pattern' => "*.sql\u{2029}*.gz", 'warn_hours' => 1, 'crit_hours' => 2], 'params.pattern'],
    'pattern with an unclosed bracket' => ['file_freshness', ['path' => '/b', 'pattern' => 'backup-[0-9*.sql', 'warn_hours' => 1, 'crit_hours' => 2], 'params.pattern'],
    'pattern with a stray closing bracket' => ['file_freshness', ['path' => '/b', 'pattern' => 'backup-0-9]*.sql', 'warn_hours' => 1, 'crit_hours' => 2], 'params.pattern'],
    'pattern with an empty class' => ['file_freshness', ['path' => '/b', 'pattern' => 'backup-[]*.sql', 'warn_hours' => 1, 'crit_hours' => 2], 'params.pattern'],
    'pattern with a trailing escape' => ['file_freshness', ['path' => '/b', 'pattern' => 'backup\\', 'warn_hours' => 1, 'crit_hours' => 2], 'params.pattern'],
    'timeout above the agent cap' => ['db_ping', ['engine' => 'mysql', 'host' => 'db', 'port' => 3306, 'timeout_seconds' => 10], 'params.timeout_seconds'],
    'path as an array' => ['mountpoint_protected', ['path' => ['/mnt']], 'params.path'],
    'missing params' => ['mountpoint_protected', [], 'params'],
    'unknown type' => ['shell', ['path' => '/mnt'], 'type'],
]);

test('well-formed file globs are accepted', function (string $pattern): void {
    $server = Server::factory()->create();

    $this->actingAs(User::factory()->admin()->create())
        ->post(route('admin.servers.checks.store', $server), ['type' => 'file_freshness', 'params' => ['path' => '/mnt/backups', 'pattern' => $pattern, 'warn_hours' => 1, 'crit_hours' => 2]])
        ->assertSessionHasNoErrors();

    expect(ServerCheck::query()->sole()->params['pattern'])->toBe($pattern);
})->with(['*', '*.tar.gz', 'backup-[0-9]*.sql', 'db_[^x]?.dump', 'nightly-[a-z0-9].gz', 'literal\\[name\\].txt', 'säkerhetskopia-*.zip']);

test('hostnames and ip addresses are accepted as a database host', function (string $host): void {
    $server = Server::factory()->create();

    $this->actingAs(User::factory()->admin()->create())
        ->post(route('admin.servers.checks.store', $server), ['type' => 'db_ping', 'params' => ['engine' => 'mysql', 'host' => $host, 'port' => 3306]])
        ->assertSessionHasNoErrors();

    expect(ServerCheck::query()->sole()->params['host'])->toBe($host);
})->with(['localhost', 'db.home.lan', '10.0.0.5', '::1', 'fd00::5']);

test('editing, toggling and deleting are audited and bump the config version', function (): void {
    $server = Server::factory()->create(['name' => 'babel']);
    $serverCheck = ServerCheck::factory()->for($server)->fileFreshness()->create();
    $server->refresh();
    $version = $server->config_version;
    $this->actingAs(User::factory()->admin()->create());

    $this->from(route('admin.servers.show', $server))
        ->put(route('admin.servers.checks.update', [$server, $serverCheck]), [
            'type' => 'db_ping',
            'is_enabled' => true,
            'params' => ['path' => '/mnt/nightly', 'warn_hours' => 12, 'crit_hours' => 24, 'junk' => 'x'],
        ])
        ->assertRedirect(route('admin.servers.show', $server))
        ->assertSessionHas('inertia.flash_data.toast.message', 'Check saved.');

    $serverCheck->refresh();

    expect($serverCheck->type)->toBe(HostCheckType::FileFreshness)
        ->and(sortedServerCheckParams($serverCheck))->toBe(['crit_hours' => 24, 'path' => '/mnt/nightly', 'warn_hours' => 12])
        ->and($server->fresh()?->config_version)->toBe($version + 1)
        ->and(ActivityLog::query()->where('action', 'server_check.updated')->sole()->description)
        ->toBe('Updated check "File freshness: /mnt/nightly" on server "babel".');

    /** @var array{params: array{from: array<string, mixed>, to: array<string, mixed>}} $changes */
    $changes = ActivityLog::query()->where('action', 'server_check.updated')->sole()->metadata['changes'];

    expect(array_keys($changes))->toBe(['params'])
        ->and(sortedServerCheckParams($changes['params']['from']))->toBe(['crit_hours' => 48, 'path' => '/mnt/backups/daily', 'pattern' => '*.tar.gz', 'warn_hours' => 26])
        ->and(sortedServerCheckParams($changes['params']['to']))->toBe(['crit_hours' => 24, 'path' => '/mnt/nightly', 'warn_hours' => 12]);

    $this->patch(route('admin.servers.checks.toggle', [$server, $serverCheck]), ['is_enabled' => false])
        ->assertSessionHas('inertia.flash_data.toast.message', 'Check paused.');

    expect($serverCheck->fresh()?->is_enabled)->toBeFalse()
        ->and($server->fresh()?->config_version)->toBe($version + 2)
        ->and(ActivityLog::query()->where('action', 'server_check.disabled')->sole()->description)
        ->toBe('Paused check "File freshness: /mnt/nightly" on server "babel".');

    $this->patch(route('admin.servers.checks.toggle', [$server, $serverCheck]), ['is_enabled' => true])
        ->assertSessionHas('inertia.flash_data.toast.message', 'Check enabled.');

    expect($serverCheck->fresh()?->is_enabled)->toBeTrue()
        ->and($server->fresh()?->config_version)->toBe($version + 3)
        ->and(ActivityLog::query()->where('action', 'server_check.enabled')->sole()->description)
        ->toBe('Enabled check "File freshness: /mnt/nightly" on server "babel".');

    $this->delete(route('admin.servers.checks.destroy', [$server, $serverCheck]))
        ->assertRedirect(route('admin.servers.show', $server))
        ->assertSessionHas('inertia.flash_data.toast.message', 'Check removed.');

    $activityLog = ActivityLog::query()->where('action', 'server_check.deleted')->sole();

    expect(ServerCheck::query()->count())->toBe(0)
        ->and($server->fresh()?->config_version)->toBe($version + 4)
        ->and($activityLog->description)->toBe('Removed check "File freshness: /mnt/nightly" on server "babel".')
        ->and($activityLog->metadata['context'])->toBe(['server_id' => $server->id]);
});

test('editing validates against the existing check type', function (): void {
    $server = Server::factory()->create();
    $serverCheck = ServerCheck::factory()->for($server)->dbPing()->create();
    $this->actingAs(User::factory()->admin()->create());

    $this->put(route('admin.servers.checks.update', [$server, $serverCheck]), [
        'type' => 'mountpoint_protected',
        'params' => ['path' => '/mnt/backups'],
    ])->assertSessionHasErrors(['params.engine', 'params.host', 'params.port']);

    expect(sortedServerCheckParams($serverCheck->fresh()))->toBe(['engine' => 'postgres', 'host' => '127.0.0.1', 'port' => 5432]);
});

test('saving an unchanged check writes no audit row and keeps the config version', function (string $state, array $params): void {
    $server = Server::factory()->create();
    $serverCheck = ServerCheck::factory()->for($server)->{$state}()->create();
    $version = $server->fresh()?->config_version;
    $this->actingAs(User::factory()->admin()->create());

    // Posted the way the form sends them: in CHECK_FIELDS order, numbers as strings, blanks for unset optional fields.
    $this->put(route('admin.servers.checks.update', [$server, $serverCheck]), ['is_enabled' => true, 'params' => $params])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('inertia.flash_data.toast.message', 'Check saved.');

    $this->patch(route('admin.servers.checks.toggle', [$server, $serverCheck]), ['is_enabled' => true])
        ->assertSessionHas('inertia.flash_data.toast.message', 'Check enabled.');

    expect(ActivityLog::query()->count())->toBe(0)
        ->and($server->fresh()?->config_version)->toBe($version);
})->with([
    'mountpoint_protected' => ['mountpointProtected', ['path' => '/mnt/backups']],
    'file_freshness' => ['fileFreshness', ['path' => '/mnt/backups/daily', 'pattern' => '*.tar.gz', 'warn_hours' => '26', 'crit_hours' => '48']],
    'db_ping' => ['dbPing', ['engine' => 'postgres', 'host' => '127.0.0.1', 'port' => '5432', 'timeout_seconds' => '']],
]);

test('toggling needs is_enabled', function (): void {
    $server = Server::factory()->create();
    $serverCheck = ServerCheck::factory()->for($server)->create();

    $this->actingAs(User::factory()->admin()->create())
        ->patch(route('admin.servers.checks.toggle', [$server, $serverCheck]), [])
        ->assertSessionHasErrors('is_enabled');
});

test('a check of another server answers 404', function (): void {
    $server = Server::factory()->create();
    $serverCheck = ServerCheck::factory()->for(Server::factory())->mountpointProtected()->create();
    $this->actingAs(User::factory()->admin()->create());

    $this->put(route('admin.servers.checks.update', [$server, $serverCheck]), ['params' => ['path' => '/mnt/other']])->assertNotFound();
    $this->patch(route('admin.servers.checks.toggle', [$server, $serverCheck]), ['is_enabled' => false])->assertNotFound();
    $this->delete(route('admin.servers.checks.destroy', [$server, $serverCheck]))->assertNotFound();

    expect($serverCheck->fresh()?->params)->toBe(['path' => '/mnt/backups'])
        ->and($serverCheck->fresh()?->is_enabled)->toBeTrue();
});

test('members and viewers cannot manage checks', function (string $role): void {
    $server = Server::factory()->create();
    $serverCheck = ServerCheck::factory()->for($server)->create();
    $user = $role === 'member' ? User::factory()->member()->create() : User::factory()->create();
    $this->actingAs($user);

    $this->post(route('admin.servers.checks.store', $server), ['type' => 'mountpoint_protected', 'params' => ['path' => '/mnt']])->assertForbidden();
    $this->put(route('admin.servers.checks.update', [$server, $serverCheck]), ['params' => ['path' => '/mnt']])->assertForbidden();
    $this->patch(route('admin.servers.checks.toggle', [$server, $serverCheck]), ['is_enabled' => false])->assertForbidden();
    $this->delete(route('admin.servers.checks.destroy', [$server, $serverCheck]))->assertForbidden();

    expect(ServerCheck::query()->sole()->params)->toBe(['path' => '/mnt/backups'])
        ->and(ServerCheck::query()->sole()->is_enabled)->toBeTrue();
})->with(['member', 'viewer']);
