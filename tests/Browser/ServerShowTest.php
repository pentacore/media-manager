<?php

declare(strict_types=1);

use App\Enums\HostCheckStatus;
use App\Models\Server;
use App\Models\ServerCheck;
use App\Models\ServerFilesystemSample;
use App\Models\ServerIncident;
use App\Models\ServerMetricSample;
use App\Models\User;
use App\Services\HostMonitoring\AgentRelease;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * A reporting server whose latest report also lists an NFS mount that stopped answering.
 */
function serverShowReportingServer(array $attributes = []): Server
{
    $server = Server::factory()->reporting()->create($attributes);
    $server->update(['last_report' => [...$server->last_report, 'filesystems' => [
        ...$server->last_report['filesystems'],
        ['mount' => '/mnt/nas', 'device' => 'nas:/media', 'fstype' => 'nfs4', 'unresponsive' => true, 'total_bytes' => null, 'used_bytes' => null, 'available_bytes' => null],
    ]]]);

    return $server;
}

test('an incident is visible on the server page with its anchor', function (): void {
    Cache::put(AgentRelease::CACHE_KEY, '0.3.0');
    $this->actingAs(User::factory()->admin()->create());
    $server = serverShowReportingServer(['agent_version' => '0.2.0']);
    $serverIncident = ServerIncident::factory()->for($server)->open()->critical()->key('disk:/')->create(['title' => 'Disk / is 95% full', 'detail' => '5.0 GB left of 100 GB.']);
    $resolved = ServerIncident::factory()->for($server)->resolved()->create(['title' => 'Backups were stale']);
    $serverCheck = ServerCheck::factory()->for($server)->mountPresent()->withResult(HostCheckStatus::Crit, '/mnt/backups is not mounted')->create();
    DB::table('server_checks')->where('id', $serverCheck->id)->update(['last_observed' => '{"mounted": false}']);
    $row = sprintf('[data-incident-row="%d"]', $serverIncident->id);

    $webpage = visit(route('admin.servers.show', $server, absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn($row.' [data-incident-title]', 'Disk / is 95% full')
        ->assertSeeIn($row.' [data-incident-detail]', '5.0 GB left of 100 GB.')
        ->assertVisible($row.' [data-incident-severity="critical"]')
        ->assertVisible(sprintf('[data-incident-row="%d"] [data-incident-resolved]', $resolved->id))
        ->assertScript(sprintf("document.getElementById('incident-%d') !== null", $serverIncident->id), true)
        ->assertScript('[...document.querySelectorAll("[data-incident-row]")].map((row) => row.dataset.incidentStatus).join(",")', 'open,resolved');

    // visit() drops a URL fragment, so load the notification link's shape (path + query + #incident-id) from the page itself.
    $webpage->script(sprintf("location.assign(location.pathname + '?range=6h#incident-%d')", $serverIncident->id));
    $webpage->script(confirmDialogWaitUntilScript("document.readyState === 'complete' && location.search === '?range=6h' && document.querySelector('#app')?.__vue_app__ !== undefined"));

    $webpage->assertScript('location.hash', '#incident-'.$serverIncident->id)
        ->assertScript('document.querySelector("[data-incident-linked]")?.dataset.incidentRow', (string) $serverIncident->id)
        ->assertScript(sprintf('(() => { const rect = document.getElementById("incident-%d").getBoundingClientRect(); return rect.top >= 0 && rect.top < window.innerHeight; })()', $serverIncident->id), true);

    $webpage->assertVisible('[data-server-facts] [data-server-agent-outdated]')
        ->assertSeeIn('[data-server-uptime]', '1d 0h')
        ->assertSeeIn('[data-fs-row="/"] [data-fs-usage]', '30.0%')
        ->assertSeeIn('[data-fs-row="/mnt/nas"] [data-fs-unresponsive]', 'Not responding')
        ->assertSeeIn(sprintf('[data-check-row="%d"] [data-check-status="crit"]', $serverCheck->id), 'Critical')
        ->assertSeeIn(sprintf('[data-check-row="%d"] [data-check-observed]', $serverCheck->id), 'mounted: false');
});

test('switching the range reloads the graphs', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    $server = Server::factory()->reporting()->create();
    ServerMetricSample::factory()->for($server)->at(now()->startOfMinute())->create(['cpu_percent' => 40]);
    ServerFilesystemSample::factory()->for($server)->at(now()->startOfMinute())->create();
    ServerMetricSample::factory()->for($server)->resolution(ServerMetricSample::FIVE_MINUTES)->at(now()->subDays(3))->create(['cpu_percent' => 10]);
    ServerMetricSample::factory()->for($server)->resolution(ServerMetricSample::FIVE_MINUTES)->at(now()->subDays(10))->create(['cpu_percent' => 20]);

    $webpage = visit(route('admin.servers.show', $server, absolute: false))
        ->assertNoSmoke()
        ->assertVisible('[data-metrics-panel] [data-metrics-chart="cpu"]')
        ->assertVisible('[data-metrics-chart="memory"]')
        ->assertVisible('[data-metrics-chart-fs="/"]')
        ->assertAttribute('[data-metrics-range="6h"]', 'aria-pressed', 'true')
        ->assertCount('[data-metrics-chart="cpu"] rect', 1);
    $webpage->script(appHydratedScript());

    $webpage->click('[data-metrics-range="30d"]');
    $webpage->script(confirmDialogWaitUntilScript('document.querySelectorAll(\'[data-metrics-chart="cpu"] rect\').length === 2'));

    $webpage->assertAttribute('[data-metrics-range="30d"]', 'aria-pressed', 'true')
        ->assertAttribute('[data-metrics-range="6h"]', 'aria-pressed', 'false')
        ->assertCount('[data-metrics-chart="cpu"] rect', 2)
        ->assertVisible('[data-metrics-chart="cpu"]')
        ->assertMissing('[data-metrics-chart-fs="/"]')
        ->assertQueryStringHas('range', '30d')
        ->assertScript('document.querySelector(\'[data-metrics-chart="cpu"] rect title\').textContent.endsWith(": 20%")', true);
});

test('a new incident appears live', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    $server = Server::factory()->reporting()->create();

    $webpage = visit(route('admin.servers.show', $server, absolute: false))
        ->assertNoSmoke()
        ->assertVisible('[data-incidents-empty]')
        ->assertSeeIn('[data-server-cpu]', '12.5%')
        ->assertVisible('[data-server-status="ok"]');
    $webpage->script(appHydratedScript());

    $serverIncident = ServerIncident::factory()->for($server)->open()->critical()->create(['title' => 'Mount /mnt/backups is missing']);
    $webpage->script(echoEmitScript('admin.servers', 'ServerIncidentChanged', [
        'server_id' => $server->id, 'incident_id' => $serverIncident->id, 'key' => $serverIncident->key,
        'status' => 'open', 'severity' => 'critical', 'title' => $serverIncident->title, 'occurred_at' => now()->toIso8601String(),
    ]));

    $webpage->assertSeeIn(sprintf('[data-incident-row="%d"] [data-incident-title]', $serverIncident->id), 'Mount /mnt/backups is missing')
        ->assertVisible('[data-server-status="incident"]');

    $server->update(['last_report' => [...$server->last_report, 'cpu_percent' => 87.5]]);
    $webpage->script(echoEmitScript('admin.servers', 'ServerReported', [
        'server_id' => $server->id, 'last_report_at' => now()->toIso8601String(),
    ]));

    $webpage->assertSeeIn('[data-server-cpu]', '87.5%');
});

test('the server page fits a phone screen', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    $server = serverShowReportingServer(['name' => 'mailserver01.internal.unreasonablylongdomainname.example']);
    ServerIncident::factory()->for($server)->open()->critical()->key('fill:/srv/media/library/very/deep/mount')->create([
        'title' => 'Filesystem /srv/media/library/very/deep/mount fills in 3 hours',
    ]);
    ServerCheck::factory()->for($server)->fileFreshness()->withResult(HostCheckStatus::Warn, 'Newest backup is 30 hours old')->create();
    ServerMetricSample::factory()->for($server)->at(now()->startOfMinute())->create();
    ServerFilesystemSample::factory()->for($server)->mount('/srv/media/library/very/deep/mount')->at(now()->startOfMinute())->create();

    $webpage = visit(route('admin.servers.show', $server, absolute: false))->on()->mobile()
        ->assertNoSmoke()
        ->assertVisible('[data-metrics-chart-fs="/srv/media/library/very/deep/mount"]')
        ->assertVisible('[data-page-header-more]')
        ->assertMissing('[data-server-delete]');

    $webpage->assertScript(phoneOverflowScript(), '')
        ->assertScript('(() => { const rect = document.querySelector("[data-page-header] h1").getBoundingClientRect(); return rect.left >= 0 && rect.right <= window.innerWidth; })()', true);
});
