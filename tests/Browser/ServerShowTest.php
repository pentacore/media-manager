<?php

declare(strict_types=1);

use App\Enums\HostCheckStatus;
use App\Models\ActivityLog;
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

/**
 * Holds back the answer to the page's first deferred `metrics` request, as a slow network would: it is sent and
 * answered at once, but the page only sees the answer when window.__mmReleaseHeld() runs.
 */
function serverShowHoldMetricsScript(): string
{
    return <<<'JS'
        (() => {
            window.__mmHeldAnswered = false;
            window.__mmReleaseHeld = () => {};
            let armed = true;
            const setRequestHeader = XMLHttpRequest.prototype.setRequestHeader;
            XMLHttpRequest.prototype.setRequestHeader = function (name, value) {
                if (String(name).toLowerCase() === 'x-inertia-partial-data' && String(value).split(',').includes('metrics')) {
                    this.__mmMetrics = true;
                }
                return setRequestHeader.call(this, name, value);
            };
            const send = XMLHttpRequest.prototype.send;
            XMLHttpRequest.prototype.send = function (body) {
                if (armed && this.__mmMetrics) {
                    armed = false;
                    const onload = this.onload;
                    this.onload = (...args) => {
                        window.__mmReleaseHeld = () => onload.apply(this, args);
                        window.__mmHeldAnswered = true;
                    };
                }
                return send.call(this, body);
            };
        })()
    JS;
}

test('an incident is visible on the server page with its anchor', function (): void {
    Cache::put(AgentRelease::CACHE_KEY, '0.3.0');
    $this->actingAs(User::factory()->admin()->create());
    $server = serverShowReportingServer(['agent_version' => '0.2.0']);
    $serverIncident = ServerIncident::factory()->for($server)->open()->critical()->key('disk:/')->create(['title' => 'Disk / is 95% full', 'detail' => '5.0 GB left of 100 GB.']);
    $resolved = collect(range(1, 18))->map(fn (int $hours): ServerIncident => ServerIncident::factory()->for($server)->resolved()->create([
        'title' => sprintf('Backups were stale %d', $hours),
        'resolved_at' => now()->subHours($hours),
    ]));
    $deepIncident = $resolved[14];
    $serverCheck = ServerCheck::factory()->for($server)->mountPresent()->withResult(HostCheckStatus::Crit, '/mnt/backups is not mounted')->create();
    DB::table('server_checks')->where('id', $serverCheck->id)->update(['last_observed' => '{"mounted": false}']);
    $row = sprintf('[data-incident-row="%d"]', $serverIncident->id);
    $deepRowTop = sprintf('document.getElementById("incident-%d").getBoundingClientRect().top', $deepIncident->id);

    $webpage = visit(route('admin.servers.show', $server, absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn($row.' [data-incident-title]', 'Disk / is 95% full')
        ->assertSeeIn($row.' [data-incident-detail]', '5.0 GB left of 100 GB.')
        ->assertVisible($row.' [data-incident-severity="critical"]')
        ->assertVisible(sprintf('[data-incident-row="%d"] [data-incident-resolved]', $resolved[0]->id))
        ->assertScript(sprintf("document.getElementById('incident-%d') !== null", $serverIncident->id), true)
        ->assertScript('[...document.querySelectorAll("[data-incident-row]")].map((row) => row.dataset.incidentStatus).join(",")', 'open'.str_repeat(',resolved', 18))
        ->assertMissing('[data-incident-linked]')
        ->assertScript($deepRowTop.' > window.innerHeight', true);

    // visit() drops a URL fragment, so load the notification link's shape (path + query + #incident-id) from the page itself.
    $webpage->script(sprintf("location.assign(location.pathname + '?range=6h#incident-%d')", $deepIncident->id));
    $webpage->script(confirmDialogWaitUntilScript("document.readyState === 'complete' && location.search === '?range=6h' && document.querySelector('#app')?.__vue_app__ !== undefined"));
    $webpage->script(confirmDialogWaitUntilScript($deepRowTop.' < window.innerHeight'));

    // The row started below the fold; the page scrolled until all of it is on screen.
    $webpage->assertScript('location.hash', '#incident-'.$deepIncident->id)
        ->assertScript('document.querySelector("[data-incident-linked]")?.dataset.incidentRow', (string) $deepIncident->id)
        ->assertScript('document.scrollingElement.scrollTop > 0', true)
        ->assertScript(sprintf('(() => { const rect = document.getElementById("incident-%d").getBoundingClientRect(); return rect.top >= 0 && rect.bottom <= window.innerHeight; })()', $deepIncident->id), true);

    $webpage->assertVisible('[data-server-facts] [data-server-agent-outdated]')
        ->assertSeeIn('[data-server-uptime]', '1d 0h')
        ->assertSeeIn('[data-fs-row="/"] [data-fs-usage]', '30.0%')
        ->assertSeeIn('[data-fs-row="/mnt/nas"] [data-fs-unresponsive]', 'Not responding')
        ->assertSeeIn(sprintf('[data-check-row="%d"] [data-check-status="crit"]', $serverCheck->id), 'Critical')
        ->assertSeeIn(sprintf('[data-check-row="%d"] [data-check-observed]', $serverCheck->id), 'mounted: false');
});

test('switching the range reloads the graphs', function (): void {
    $this->actingAs(User::factory()->admin()->create(['preferences' => ['timezone' => 'Asia/Kolkata', 'time_format' => '24h', 'date_format' => 'iso']]));
    $server = Server::factory()->reporting()->create();
    $sampledAt = now()->startOfMinute();
    ServerMetricSample::factory()->for($server)->at($sampledAt)->create(['cpu_percent' => 40]);
    ServerFilesystemSample::factory()->for($server)->at($sampledAt)->create();
    ServerMetricSample::factory()->for($server)->resolution(ServerMetricSample::FIVE_MINUTES)->at(now()->subDays(3))->create(['cpu_percent' => 10]);
    ServerMetricSample::factory()->for($server)->resolution(ServerMetricSample::FIVE_MINUTES)->at(now()->subDays(10))->create(['cpu_percent' => 20]);
    $tenDaysAgo = now()->subDays(10);
    $realBars = '[data-metrics-chart="cpu"] rect[data-bar-placeholder="false"]';
    $firstAxisLabel = 'document.querySelector(\'[data-metrics-chart="cpu"] svg\').nextElementSibling.firstElementChild.textContent.trim()';
    $firstRealTooltip = sprintf("document.querySelector('%s title').textContent", $realBars);

    $webpage = visit(route('admin.servers.show', $server, absolute: false))
        ->assertNoSmoke()
        ->assertVisible('[data-metrics-panel] [data-metrics-chart="cpu"]')
        ->assertVisible('[data-metrics-chart="memory"]')
        ->assertVisible('[data-metrics-chart-fs="/"]')
        ->assertAttribute('[data-metrics-range="6h"]', 'aria-pressed', 'true');
    $webpage->script(confirmDialogWaitUntilScript('document.querySelectorAll(\'[data-metrics-chart="cpu"] rect\').length === 120'));

    $webpage->assertScript('document.querySelectorAll(\'[data-metrics-chart="cpu"] rect\').length', 120)
        ->assertScript(sprintf("document.querySelectorAll('%s').length", $realBars), 1)
        // Times are the viewer's (Asia/Kolkata), not the app's UTC; 6 h buckets are three minutes wide.
        ->assertScript($firstRealTooltip, sprintf('%s: 40%%', $sampledAt->subMinutes($sampledAt->minute % 3)->setTimezone('Asia/Kolkata')->format('Y-m-d H:i')))
        ->assertScript(sprintf('/^\\d{2}:\\d{2}$/.test(%s)', $firstAxisLabel), true);
    $webpage->script(appHydratedScript());

    $webpage->click('[data-metrics-range="30d"]');
    $webpage->script(confirmDialogWaitUntilScript(sprintf("document.querySelectorAll('%s').length === 2", $realBars)));

    $webpage->assertAttribute('[data-metrics-range="30d"]', 'aria-pressed', 'true')
        ->assertAttribute('[data-metrics-range="6h"]', 'aria-pressed', 'false')
        ->assertScript('document.querySelectorAll(\'[data-metrics-chart="cpu"] rect\').length', 120)
        ->assertScript(sprintf("document.querySelectorAll('%s').length", $realBars), 2)
        ->assertMissing('[data-metrics-chart-fs="/"]')
        ->assertQueryStringHas('range', '30d')
        // 30 d buckets are six hours wide, counted from midnight.
        ->assertScript($firstRealTooltip, sprintf('%s: 20%%', $tenDaysAgo->startOfDay()->addHours(intdiv($tenDaysAgo->hour, 6) * 6)->setTimezone('Asia/Kolkata')->format('Y-m-d H:i')))
        ->assertScript(sprintf('/^\\d{4}-\\d{2}-\\d{2} \\d{2}:\\d{2}$/.test(%s)', $firstAxisLabel), true)
        ->assertScript('document.querySelector(\'[data-metrics-chart="cpu"] rect[data-bar-placeholder="true"] title\').textContent.endsWith(": no data")', true);
});

test('the graphs show a skeleton until the deferred metrics arrive', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    $server = Server::factory()->reporting()->create();
    ServerMetricSample::factory()->for($server)->at(now()->startOfMinute())->create();

    $webpage = visit(route('admin.servers.index', absolute: false))->assertNoSmoke();
    $webpage->script(appHydratedScript());
    $webpage->script(serverShowHoldMetricsScript());

    $webpage->click(sprintf('[data-server-row="%d"] [data-server-link]', $server->id))
        ->assertPathIs(route('admin.servers.show', $server, absolute: false));
    $webpage->script(confirmDialogWaitUntilScript('window.__mmHeldAnswered === true'));

    $webpage->assertVisible('[data-metrics-panel] [data-metrics-loading]')
        ->assertAttribute('[data-metrics-panel]', 'aria-busy', 'true')
        ->assertMissing('[data-metrics-chart="cpu"]');

    $webpage->script('window.__mmReleaseHeld()');
    $webpage->script(confirmDialogWaitUntilScript('document.querySelector(\'[data-metrics-chart="cpu"]\') !== null'));

    $webpage->assertVisible('[data-metrics-chart="cpu"]')
        ->assertMissing('[data-metrics-loading]')
        ->assertAttribute('[data-metrics-panel]', 'aria-busy', 'false');
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
        ->assertMissing('[data-server-delete]')
        ->assertVisible('[data-check-add]')
        ->assertVisible('[data-check-row] [data-check-edit]')
        ->assertVisible('[data-check-row] [data-check-toggle]')
        ->assertVisible('[data-check-row] [data-check-delete]');

    $webpage->assertScript(phoneOverflowScript(), '')
        ->assertScript('(() => { const rect = document.querySelector("[data-page-header] h1").getBoundingClientRect(); return rect.left >= 0 && rect.right <= window.innerWidth; })()', true);

    $webpage->script(appHydratedScript());
    $webpage->click('[data-check-row] [data-check-edit]')
        ->assertVisible('[data-check-field="crit_hours"]')
        ->assertScript(phoneOverflowScript('[data-check-dialog]'), '');
});

test('an admin adds a file freshness check with per-type fields', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    $server = Server::factory()->reporting()->create();

    $webpage = visit(route('admin.servers.show', $server, absolute: false))
        ->assertNoSmoke()
        ->assertVisible('[data-checks-empty]');
    $webpage->script(appHydratedScript());

    $webpage->click('[data-check-add]')
        ->assertVisible('[data-check-field="path"]')
        ->assertVisible('[data-check-field="fstype"]')
        ->assertMissing('[data-check-field="warn_hours"]')
        ->click('[data-check-type]')
        ->click('[role="option"]:has-text("File freshness")')
        ->assertMissing('[data-check-field="fstype"]')
        ->assertSeeIn('[data-check-field="pattern"]', 'Default *')
        ->assertAttribute('[data-check-field="warn_hours"] input', 'inputmode', 'decimal')
        ->assertAttribute('[data-check-field="warn_hours"] input', 'step', 'any')
        ->fill('[data-check-field="path"] input', '/mnt/backups')
        ->fill('[data-check-field="pattern"] input', '*.tar.gz')
        ->fill('[data-check-field="warn_hours"] input', '26')
        ->fill('[data-check-field="crit_hours"] input', '48')
        ->click('[data-check-save]')
        ->assertSee('Check added.')
        ->assertSeeIn('[data-check-row-label]', 'File freshness: /mnt/backups')
        ->assertMissing('[data-check-dialog]');

    $serverCheck = ServerCheck::query()->sole();
    $params = $serverCheck->params;
    ksort($params);

    expect($serverCheck->type->value)->toBe('file_freshness')
        ->and($params)->toBe(['crit_hours' => 48, 'path' => '/mnt/backups', 'pattern' => '*.tar.gz', 'warn_hours' => 26])
        ->and($server->fresh()?->config_version)->toBe(2);
});

test('a database ping check picks its engine', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    $server = Server::factory()->reporting()->create();

    $webpage = visit(route('admin.servers.show', $server, absolute: false))->assertNoSmoke();
    $webpage->script(appHydratedScript());

    $webpage->click('[data-check-add]')
        ->click('[data-check-type]')
        ->click('[role="option"]:has-text("Database ping")')
        ->assertSeeIn('[data-check-field="timeout_seconds"]', 'Default 5, at most 9')
        ->assertAttribute('[data-check-field="port"] input', 'inputmode', 'numeric')
        ->assertAttribute('[data-check-field="port"] input', 'step', '1')
        ->click('[data-check-field="engine"] [role="combobox"]')
        ->click('[role="option"]:has-text("MySQL")')
        ->fill('[data-check-field="host"] input', 'db.lan')
        ->fill('[data-check-field="port"] input', '3306')
        ->click('[data-check-save]')
        ->assertSee('Check added.')
        ->assertSeeIn('[data-check-row-label]', 'Database ping: db.lan:3306');

    $params = ServerCheck::query()->sole()->params;
    ksort($params);

    expect($params)->toBe(['engine' => 'mysql', 'host' => 'db.lan', 'port' => 3306]);
});

test('a validation error shows under the field', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    $server = Server::factory()->reporting()->create();

    $webpage = visit(route('admin.servers.show', $server, absolute: false))->assertNoSmoke();
    $webpage->script(appHydratedScript());

    $webpage->click('[data-check-add]')
        ->click('[data-check-type]')
        ->click('[role="option"]:has-text("File freshness")')
        ->fill('[data-check-field="path"] input', '/mnt/backups')
        ->fill('[data-check-field="warn_hours"] input', '48')
        ->fill('[data-check-field="crit_hours"] input', '24')
        ->click('[data-check-save]')
        ->assertSeeIn('[data-check-field="crit_hours"]', 'The critical hours must be at least the warn hours.')
        ->assertVisible('[data-check-dialog]')
        ->assertValue('[data-check-field="path"] input', '/mnt/backups');

    expect(ServerCheck::query()->count())->toBe(0);
});

test('an existing check opens pre-filled with its type locked and saves', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    $server = Server::factory()->reporting()->create();
    $serverCheck = ServerCheck::factory()->for($server)->pathOnMount()->create();
    $row = sprintf('[data-check-row="%d"]', $serverCheck->id);

    $webpage = visit(route('admin.servers.show', $server, absolute: false))->assertNoSmoke();
    $webpage->script(appHydratedScript());

    $webpage->click($row.' [data-check-edit]')
        ->assertValue('[data-check-field="path"] input', '/mnt/backups/daily')
        ->assertValue('[data-check-field="mount"] input', '/mnt/backups')
        ->assertSeeIn('[data-check-type]', 'Path on mount')
        ->assertScript('document.querySelector("[data-check-type]").disabled', true)
        ->fill('[data-check-field="path"] input', '/mnt/backups/weekly')
        ->click('[data-check-save]')
        ->assertSee('Check saved.')
        ->assertSeeIn($row.' [data-check-row-label]', 'Path on mount: /mnt/backups/weekly');

    expect($serverCheck->fresh()?->params['path'])->toBe('/mnt/backups/weekly');

    // Adding after an edit starts from a blank form again.
    $webpage->click('[data-check-add]')
        ->assertValue('[data-check-field="path"] input', '')
        ->assertScript('document.querySelector("[data-check-type]").disabled', false);
});

test('saving an untouched check leaves the agent config alone', function (string $state): void {
    $this->actingAs(User::factory()->admin()->create());
    $server = Server::factory()->reporting()->create();
    $serverCheck = ServerCheck::factory()->for($server)->{$state}()->create();
    $version = $server->fresh()?->config_version;

    $webpage = visit(route('admin.servers.show', $server, absolute: false))->assertNoSmoke();
    $webpage->script(appHydratedScript());

    $webpage->click(sprintf('[data-check-row="%d"] [data-check-edit]', $serverCheck->id))
        ->click('[data-check-save]')
        ->assertSee('Check saved.')
        ->assertMissing('[data-check-dialog]');

    expect($server->fresh()?->config_version)->toBe($version)
        ->and(ActivityLog::query()->where('action', 'server_check.updated')->count())->toBe(0);
})->with(['fileFreshness', 'dbPing']);

test('a check can be paused and removed', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    $server = Server::factory()->reporting()->create();
    $serverCheck = ServerCheck::factory()->for($server)->mountpointProtected()->create();
    $row = sprintf('[data-check-row="%d"]', $serverCheck->id);

    $webpage = visit(route('admin.servers.show', $server, absolute: false))
        ->assertNoSmoke()
        ->assertMissing($row.' [data-check-disabled]')
        ->assertAttribute($row.' [data-check-toggle]', 'aria-label', 'Pause Mountpoint protected: /mnt/backups');
    $webpage->script(appHydratedScript());

    $webpage->click($row.' [data-check-toggle]')
        ->assertSee('Check paused.')
        ->assertSeeIn($row.' [data-check-disabled]', 'Paused')
        ->assertAttribute($row.' [data-check-toggle]', 'aria-label', 'Resume Mountpoint protected: /mnt/backups');

    expect($serverCheck->fresh()?->is_enabled)->toBeFalse();

    $webpage->click($row.' [data-check-delete]')
        ->click('[data-confirm-cancel]')
        ->assertVisible($row);

    $webpage->click($row.' [data-check-delete]')
        ->click('[data-confirm-accept]')
        ->assertSee('Check removed.')
        ->assertMissing($row)
        ->assertVisible('[data-checks-empty]');

    expect(ServerCheck::query()->count())->toBe(0);
});

test("the server page says when the platform is rejecting the agent's reports", function (): void {
    $this->actingAs(User::factory()->admin()->create());
    $server = Server::factory()->enrolled()->create([
        'name' => 'drifted',
        'last_report_error' => 'The metrics.cpu percent field must be a number.',
        'last_report_error_at' => now()->subMinutes(2),
    ]);

    visit(route('admin.servers.show', $server, absolute: false))
        ->assertNoSmoke()
        ->assertVisible('[data-server-report-error="latest"]')
        ->assertSeeIn('[data-server-report-error]', "The platform is rejecting this agent's reports")
        ->assertSeeIn('[data-server-report-error]', 'The metrics.cpu percent field must be a number.')
        ->assertSeeIn('[data-server-facts]', 'The agent has not reported yet.');
});

test('an older rejection stays on the server page as history', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    $server = serverShowReportingServer([
        'last_report_error' => 'The schema field is required.',
        'last_report_error_at' => now()->subHour(),
    ]);

    visit(route('admin.servers.show', $server, absolute: false))
        ->assertNoSmoke()
        ->assertVisible('[data-server-report-error="past"]')
        ->assertSeeIn('[data-server-report-error]', 'Last rejected report')
        ->assertSeeIn('[data-server-report-error]', 'The schema field is required.');
});
