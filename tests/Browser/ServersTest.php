<?php

declare(strict_types=1);

use App\Models\Server;
use App\Models\ServerIncident;
use App\Models\User;
use App\Services\HostMonitoring\AgentRelease;
use App\Settings\MonitoringSettings;
use Illuminate\Support\Facades\Cache;

beforeEach(function (): void {
    config()->set('mediamanager.monitoring.uptime_kuma_push_url');
});

test('adding a server shows its enrollment token once', function (): void {
    $this->actingAs(User::factory()->admin()->create());

    $webpage = visit(route('admin.servers.index', absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-server-empty]', 'No servers yet. Add one to install the agent.');

    $webpage->script(appHydratedScript());

    $webpage->click('[data-server-add]')
        ->fill('[data-server-name]', 'Mailserver')
        ->click('[data-server-create]')
        ->assertVisible('[data-enrollment-dialog]')
        ->assertSee('Server added. Install the agent with the command shown.')
        ->assertSeeIn('[data-enrollment-debian-command]', '--token')
        ->assertSeeIn('[data-enrollment-unraid-url]', 'lookout.plg');

    $server = Server::query()->sole();
    $token = $webpage->script("document.querySelector('[data-enrollment-token]').textContent.trim()");

    expect($server->name)->toBe('Mailserver')
        ->and(hash('sha256', (string) $token))->toBe($server->enrollment_token_hash);

    $historyHoldsToken = sprintf("JSON.stringify(window.history.state ?? {}).includes('%s')", $token);

    $webpage->assertPathIs(route('admin.servers.show', $server, absolute: false))
        ->assertSeeIn('[data-enrollment-debian-command]', (string) $token)
        ->assertScript($historyHoldsToken, false)
        ->keys('[data-enrollment-dialog]', 'Escape')
        ->assertVisible('[data-enrollment-dialog]')
        ->click('[data-enrollment-done]')
        ->assertMissing('[data-enrollment-dialog]');

    $webpage->script('window.history.back()');
    $webpage->assertPathIs(route('admin.servers.index', absolute: false));
    $webpage->script('window.history.forward()');
    $webpage->assertPathIs(route('admin.servers.show', $server, absolute: false))
        ->assertSeeIn('[data-server-description]', 'No agent has enrolled yet.')
        ->assertMissing('[data-enrollment-dialog]')
        ->assertDontSee((string) $token)
        ->assertScript($historyHoldsToken, false);

    $webpage->navigate(route('admin.servers.show', $server, absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-server-description]', 'No agent has enrolled yet.')
        ->assertMissing('[data-enrollment-dialog]')
        ->assertDontSee((string) $token);
});

test('a new enrollment token opens the dialog with a token for this server and keeps the agent token', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    $server = Server::factory()->enrolled()->create(['name' => 'babel']);

    $webpage = visit(route('admin.servers.show', $server, absolute: false))
        ->assertNoSmoke()
        ->assertMissing('[data-enrollment-dialog]');

    $webpage->script(appHydratedScript());

    $webpage->click('[data-server-regenerate]')
        ->assertVisible('[data-enrollment-dialog]')
        ->assertSee('New enrollment token created.');

    $token = $webpage->script("document.querySelector('[data-enrollment-token]').textContent.trim()");

    expect(hash('sha256', (string) $token))->toBe($server->fresh()->enrollment_token_hash)
        ->and($server->fresh()->token_hash)->toBe($server->token_hash);
});

test('removing a server asks first and returns to the index', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    $server = Server::factory()->create(['name' => 'old-box']);

    $webpage = visit(route('admin.servers.show', $server, absolute: false))->assertNoSmoke();
    $webpage->script(appHydratedScript());

    $webpage->click('[data-server-delete]')
        ->click('[data-confirm-accept]')
        ->assertPathIs(route('admin.servers.index', absolute: false))
        ->assertSee('Server removed.')
        ->assertVisible('[data-server-empty]');

    expect(Server::query()->count())->toBe(0);
});

test('the index shows status, outdated agents and open incident counts', function (): void {
    Cache::put(AgentRelease::CACHE_KEY, '0.3.0');
    $this->actingAs(User::factory()->admin()->create());
    $incidentServer = Server::factory()->reporting()->agentVersion('0.2.0')->create(['name' => 'babel', 'hostname' => 'babel.lan']);
    ServerIncident::factory()->for($incidentServer)->open()->critical()->count(2)->create();
    $okServer = Server::factory()->reporting()->agentVersion('0.3.0')->create(['name' => 'mail']);
    $pendingServer = Server::factory()->pendingEnrollment()->create(['name' => 'turbo']);

    $incidentRow = sprintf('[data-server-row="%d"]', $incidentServer->id);
    $okRow = sprintf('[data-server-row="%d"]', $okServer->id);
    $pendingRow = sprintf('[data-server-row="%d"]', $pendingServer->id);

    visit(route('admin.servers.index', absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-server-latest-version]', '0.3.0')
        ->assertSeeIn($incidentRow, 'babel.lan')
        ->assertVisible($incidentRow.' [data-server-status="incident"]')
        ->assertSeeIn($incidentRow.' [data-server-outdated]', 'Outdated')
        ->assertSeeIn($incidentRow.' [data-server-open-incidents]', '2')
        ->assertVisible($okRow.' [data-server-status="ok"]')
        ->assertMissing($okRow.' [data-server-outdated]')
        ->assertSeeIn($okRow.' [data-server-open-incidents]', '0')
        ->assertVisible($pendingRow.' [data-server-status="pending"]')
        ->assertSeeIn($pendingRow, 'Never')
        ->click($incidentRow.' [data-server-link]')
        ->assertPathIs(route('admin.servers.show', $incidentServer, absolute: false));
});

test('the index updates live when a server reports or an incident changes', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    $server = Server::factory()->enrolled()->create(['name' => 'babel']);
    $row = sprintf('[data-server-row="%d"]', $server->id);

    $webpage = visit(route('admin.servers.index', absolute: false))
        ->assertNoSmoke()
        ->assertVisible($row.' [data-server-status="pending"]')
        ->assertSeeIn($row, 'Never');
    $webpage->script(appHydratedScript());

    $server->update(['last_report_at' => now()]);
    $webpage->script(echoEmitScript('admin.servers', 'ServerReported', [
        'server_id' => $server->id, 'last_report_at' => now()->toIso8601String(),
    ]));

    $webpage->assertVisible($row.' [data-server-status="ok"]')
        ->assertDontSeeIn($row, 'Never')
        ->assertSeeIn($row.' [data-server-open-incidents]', '0');

    $serverIncident = ServerIncident::factory()->for($server)->open()->critical()->create(['title' => 'Disk almost full']);
    $webpage->script(echoEmitScript('admin.servers', 'ServerIncidentChanged', [
        'server_id' => $server->id, 'incident_id' => $serverIncident->id, 'key' => $serverIncident->key,
        'status' => 'open', 'severity' => 'critical', 'title' => 'Disk almost full', 'occurred_at' => now()->toIso8601String(),
    ]));

    $webpage->assertVisible($row.' [data-server-status="incident"]')
        ->assertSeeIn($row.' [data-server-open-incidents]', '1');
});

test('the watcher url saves and shows only its last four characters', function (): void {
    $this->actingAs(User::factory()->admin()->create());

    $webpage = visit(route('admin.servers.index', absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-watcher-hint]', 'No push URL saved.')
        ->assertMissing('[data-watcher-clear]')
        ->assertSeeIn('[data-watcher-settings]', 'private network');

    $webpage->script(appHydratedScript());

    $webpage->fill('[data-watcher-push-url]', 'https://kuma.test/api/push/secretz9k2')
        ->click('[data-watcher-save]')
        ->assertSee('Outside watcher saved.')
        ->assertSeeIn('[data-watcher-hint]', '…z9k2')
        ->assertValue('[data-watcher-push-url]', '')
        ->assertDontSee('secretz9k2');

    expect(resolve(MonitoringSettings::class)->uptimeKumaPushUrl())->toBe('https://kuma.test/api/push/secretz9k2');

    $webpage->click('[data-watcher-clear]')
        ->click('[data-confirm-accept]')
        ->assertSeeIn('[data-watcher-hint]', 'No push URL saved.')
        ->assertMissing('[data-watcher-clear]');

    Cache::flush();

    expect(resolve(MonitoringSettings::class)->uptimeKumaPushUrl())->toBeNull();
});

test('a watcher url from the environment cannot be removed from the page and says where it comes from', function (): void {
    config()->set('mediamanager.monitoring.uptime_kuma_push_url', 'https://kuma.test/api/push/fromenvq7w1');
    $this->actingAs(User::factory()->admin()->create());

    visit(route('admin.servers.index', absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-watcher-hint]', 'comes from the environment')
        ->assertSeeIn('[data-watcher-hint]', '…q7w1')
        ->assertSeeIn('[data-watcher-environment-note]', 'MEDIAMANAGER_UPTIME_KUMA_PUSH_URL')
        ->assertMissing('[data-watcher-clear]')
        ->assertDontSee('fromenvq7w1');
});

test('the enrollment dialog fits a phone screen', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    $server = Server::factory()->create(['name' => 'a-server-with-a-rather-long-name']);

    $webpage = visit(route('admin.servers.show', $server, absolute: false))->on()->mobile()->assertNoSmoke();
    $webpage->script(appHydratedScript());

    $webpage->click('[data-page-header-more]')
        ->click('[data-page-header-item="regenerate"]')
        ->assertVisible('[data-enrollment-dialog]');

    $webpage->script(confirmDialogWaitUntilScript('document.querySelector("[data-enrollment-dialog]").getAnimations().length === 0'));

    $webpage->assertScript(phoneOverflowScript('[data-enrollment-dialog]'), '')
        ->assertScript('(() => { const rect = document.querySelector("[data-enrollment-dialog]").getBoundingClientRect(); return rect.left >= 0 && rect.right <= window.innerWidth && rect.top >= 0 && rect.bottom <= window.innerHeight; })()', true);
});
