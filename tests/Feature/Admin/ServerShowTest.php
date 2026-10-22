<?php

declare(strict_types=1);

use App\Enums\HostCheckStatus;
use App\Models\Server;
use App\Models\ServerCheck;
use App\Models\ServerIncident;
use App\Models\ServerMetricSample;
use App\Models\User;
use App\Services\HostMonitoring\AgentRelease;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    Cache::flush();
});

test('the server page shows facts, checks and incidents with metrics deferred', function (): void {
    Cache::put(AgentRelease::CACHE_KEY, '0.3.0');
    $server = Server::factory()->reporting()->agentVersion('0.2.0')->create(['name' => 'babel', 'hostname' => 'babel.lan']);
    $server->update(['last_report' => [...$server->last_report, 'filesystems' => [
        ...$server->last_report['filesystems'],
        ['mount' => '/mnt/nas', 'device' => 'nas:/media', 'fstype' => 'nfs4', 'unresponsive' => true, 'total_bytes' => null, 'used_bytes' => null, 'available_bytes' => null],
    ]]]);
    $serverCheck = ServerCheck::factory()->for($server)->mountPresent()->withResult(HostCheckStatus::Crit, '/mnt/backups is not mounted')->create();
    DB::table('server_checks')->where('id', $serverCheck->id)->update(['last_observed' => '{"mounted": false}']);
    $serverIncident = ServerIncident::factory()->for($server)->open()->critical()->key('disk:/')->create(['title' => 'Disk / is 95% full']);
    ServerMetricSample::factory()->for($server)->at(now()->startOfMinute())->create(['cpu_percent' => 42]);

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.servers.show', $server))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/Servers/Show')
            ->where('server.id', $server->id)
            ->where('server.uuid', $server->uuid)
            ->where('server.name', 'babel')
            ->where('server.hostname', 'babel.lan')
            ->where('server.kernel', '6.1.0-18-amd64')
            ->where('server.agent_version', '0.2.0')
            ->where('server.agent_outdated', true)
            ->where('server.status', 'incident')
            ->where('server.status_label', 'Incident')
            ->where('server.uptime_seconds', 86_400)
            ->where('server.report_interval_seconds', $server->fresh()->report_interval_seconds)
            ->where('latest.cpu_percent', 12.5)
            ->where('latest.load', [0.3, 0.2, 0.1])
            ->where('latest.memory_used_percent', 25)
            ->where('latest.filesystems.0', [
                'mount' => '/', 'fstype' => 'ext4', 'unresponsive' => false, 'used_percent' => 30,
                'used_bytes' => 32_212_254_720, 'total_bytes' => 107_374_182_400, 'available_bytes' => 75_161_927_680,
            ])
            ->where('latest.filesystems.1', [
                'mount' => '/mnt/nas', 'fstype' => 'nfs4', 'unresponsive' => true, 'used_percent' => null,
                'used_bytes' => null, 'total_bytes' => null, 'available_bytes' => null,
            ])
            ->where('latest.failed_units', [])
            ->where('latest.containers', [])
            ->has('checks', 1)
            ->where('checks.0.id', $serverCheck->id)
            ->where('checks.0.uuid', $serverCheck->uuid)
            ->where('checks.0.type', 'mount_present')
            ->where('checks.0.type_label', 'Mount present')
            ->where('checks.0.label', 'Mount present: /mnt/backups')
            ->where('checks.0.params.path', '/mnt/backups')
            ->where('checks.0.is_enabled', true)
            ->where('checks.0.last_status', 'crit')
            ->where('checks.0.last_status_label', 'Critical')
            ->where('checks.0.last_message', '/mnt/backups is not mounted')
            ->where('checks.0.last_observed', ['mounted' => false])
            ->has('checks.0.last_evaluated_at')
            ->has('checkTypes', 5)
            ->where('checkTypes.0', ['value' => 'mount_present', 'label' => 'Mount present'])
            ->where('dbEngines', [['value' => 'postgres', 'label' => 'PostgreSQL'], ['value' => 'mysql', 'label' => 'MySQL']])
            ->has('incidents', 1)
            ->where('incidents.0.id', $serverIncident->id)
            ->where('incidents.0.key', 'disk:/')
            ->where('incidents.0.severity', 'critical')
            ->where('incidents.0.status', 'open')
            ->where('incidents.0.title', 'Disk / is 95% full')
            ->has('incidents.0.opened_at')
            ->has('incidents.0.last_seen_at')
            ->where('incidents.0.resolved_at', null)
            ->where('range', '6h')
            ->has('ranges', 4)
            ->missing('metrics')
            ->loadDeferredProps('metrics', fn ($reload) => $reload
                ->has('metrics.cpu', 1)
                ->where('metrics.cpu.0.value', 42)
                ->has('metrics.memory', 1)
                ->has('metrics.filesystems', 0)));
});

test('a server that never reported has no latest snapshot', function (): void {
    $server = Server::factory()->pendingEnrollment()->create();

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.servers.show', $server))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('server.status', 'pending')
            ->where('server.uptime_seconds', null)
            ->where('server.agent_outdated', false)
            ->where('latest', null)
            ->has('checks', 0)
            ->has('incidents', 0));
});

test('an empty observed result reaches the page as an object', function (): void {
    $server = Server::factory()->reporting()->create();
    $serverCheck = ServerCheck::factory()->for($server)->withResult(HostCheckStatus::Ok)->create();
    DB::table('server_checks')->where('id', $serverCheck->id)->update(['last_observed' => '{}']);

    $response = $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.servers.show', $server))
        ->assertOk();

    expect(json_encode($response->viewData('page')['props']['checks'][0]['last_observed'], JSON_THROW_ON_ERROR))->toBe('{}');
});

test('an unknown range falls back to six hours', function (string $query): void {
    $server = Server::factory()->create();

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.servers.show', $server).$query)
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('range', '6h'));
})->with([
    'unknown value' => ['?range=bogus'],
    'array value' => ['?range[]=30d'],
]);

test('a known range is kept', function (): void {
    $server = Server::factory()->create();

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.servers.show', ['server' => $server, 'range' => '30d']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('range', '30d'));
});

test('open incidents come before resolved ones', function (): void {
    $server = Server::factory()->reporting()->create();
    $resolvedRecently = ServerIncident::factory()->for($server)->resolved()->create(['resolved_at' => now()->subMinute()]);
    $openWarning = ServerIncident::factory()->for($server)->open()->warning()->create(['opened_at' => now()->subMinutes(5)]);
    $openCritical = ServerIncident::factory()->for($server)->open()->critical()->create(['opened_at' => now()->subHour()]);
    ServerIncident::factory()->for($server)->resolved()->count(20)->create(['resolved_at' => now()->subDays(2)]);
    ServerIncident::factory()->open()->create();

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.servers.show', $server))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('incidents', 22)
            ->where('incidents.0.id', $openCritical->id)
            ->where('incidents.1.id', $openWarning->id)
            ->where('incidents.2.id', $resolvedRecently->id)
            ->where('incidents.21.status', 'resolved'));
});

test('the server page reads the server row once', function (): void {
    $server = Server::factory()->reporting()->create();
    ServerIncident::factory()->for($server)->open()->key('silent')->create();
    $this->actingAs(User::factory()->admin()->create());
    DB::enableQueryLog();

    $this->get(route('admin.servers.show', $server))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('server.status', 'silent'));

    $fullRowReads = array_filter(DB::getQueryLog(), static fn (array $query): bool => preg_match('/^select (\*|"servers"\.\*)[ ,].*from "servers"/', $query['query']) === 1);

    expect($fullRowReads)->toHaveCount(1);
});
