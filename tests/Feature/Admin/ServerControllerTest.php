<?php

declare(strict_types=1);

use App\Models\ActivityLog;
use App\Models\Server;
use App\Models\ServerIncident;
use App\Models\User;
use App\Services\HostMonitoring\AgentRelease;
use App\Settings\MonitoringSettings;
use Illuminate\Support\Facades\Cache;
use Tests\Support\AgentReportFixtures;

beforeEach(function (): void {
    Cache::flush();
    config()->set('mediamanager.monitoring.uptime_kuma_push_url');
});

test('the index lists servers with status, version and open incidents', function (): void {
    Cache::put(AgentRelease::CACHE_KEY, '0.3.0');
    $server = Server::factory()->reporting()->agentVersion('0.2.0')->create(['name' => 'babel', 'hostname' => 'babel.lan']);
    ServerIncident::factory()->for($server)->open()->critical()->create();
    ServerIncident::factory()->for($server)->resolved()->create();
    Server::factory()->create(['name' => 'zeta']);

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.servers.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/Servers/Index')
            ->has('servers.data', 2)
            ->where('servers.data.0.id', $server->id)
            ->where('servers.data.0.name', 'babel')
            ->where('servers.data.0.hostname', 'babel.lan')
            ->where('servers.data.0.status', 'incident')
            ->where('servers.data.0.status_label', 'Incident')
            ->where('servers.data.0.last_report_at', $server->last_report_at?->toIso8601String())
            ->where('servers.data.0.agent_version', '0.2.0')
            ->where('servers.data.0.agent_outdated', true)
            ->where('servers.data.0.open_incidents_count', 1)
            ->where('servers.data.1.name', 'zeta')
            ->where('servers.data.1.status', 'pending')
            ->where('servers.data.1.last_report_at', null)
            ->where('servers.data.1.agent_outdated', false)
            ->where('servers.data.1.open_incidents_count', 0)
            ->where('servers.meta.total', 2)
            ->where('servers.meta.per_page', 25)
            ->has('servers.links')
            ->where('latestAgentVersion', '0.3.0')
            ->where('watcher', ['push_url_set' => false, 'push_url_hint' => null]));
});

test('the index shows only the last four characters of the watcher url', function (): void {
    resolve(MonitoringSettings::class)->setUptimeKumaPushUrl('https://kuma.test/api/push/secretz9k2');

    $response = $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.servers.index'))
        ->assertInertia(fn ($page) => $page
            ->where('watcher', ['push_url_set' => true, 'push_url_hint' => '…z9k2'])
            ->where('latestAgentVersion', null));

    expect($response->getContent())->not->toContain('secretz9k2');
});

test('adding a server flashes enrollment instructions once and stores only a hash', function (): void {
    config()->set('app.url', 'https://media.example.test');
    $this->actingAs(User::factory()->admin()->create());

    $response = $this->post(route('admin.servers.store'), ['name' => 'Turbo']);

    $server = Server::query()->sole();
    $response->assertRedirect(route('admin.servers.show', $server))
        ->assertSessionHas('inertia.flash_data.toast.message', 'Server added. Install the agent with the command shown.');

    /** @var array{server_id: int, token: string, expires_at: string, platform_url: string, debian_command: string, unraid_plugin_url: string} $flash */
    $flash = session('inertia.flash_data.enrollment');

    expect($server->name)->toBe('Turbo')
        ->and($flash['server_id'])->toBe($server->id)
        ->and(hash('sha256', $flash['token']))->toBe($server->enrollment_token_hash)
        ->and($flash['platform_url'])->toBe('https://media.example.test')
        ->and($flash['expires_at'])->toBe($server->enrollment_expires_at?->toIso8601String())
        ->and($flash['debian_command'])->toBe(sprintf('curl -fsSL https://github.com/pentacore/lookout/releases/latest/download/install.sh | sudo sh -s -- --url %s --token %s', 'https://media.example.test', $flash['token']))
        ->and($flash['unraid_plugin_url'])->toBe('https://github.com/pentacore/lookout/releases/latest/download/lookout.plg');

    $activityLog = ActivityLog::query()->where('action', 'server.created')->sole();

    expect($activityLog->description)->toBe('Added server "Turbo".')
        ->and(json_encode($activityLog->metadata, JSON_THROW_ON_ERROR))->not->toContain($flash['token']);

    $this->get(route('admin.servers.show', $server))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Admin/Servers/Show')->where('server.id', $server->id));

    $this->get(route('admin.servers.show', $server))->assertOk()->assertDontSee($flash['token']);
    $this->get(route('admin.servers.index'))->assertOk()->assertDontSee($flash['token']);
});

test('a duplicate name is rejected', function (): void {
    Server::factory()->create(['name' => 'Turbo']);

    $this->actingAs(User::factory()->admin()->create())
        ->post(route('admin.servers.store'), ['name' => 'Turbo'])
        ->assertSessionHasErrors('name');

    expect(Server::query()->count())->toBe(1);
});

test('a server name is required and at most 100 characters', function (mixed $name): void {
    $this->actingAs(User::factory()->admin()->create())
        ->post(route('admin.servers.store'), ['name' => $name])
        ->assertSessionHasErrors('name');

    expect(Server::query()->count())->toBe(0);
})->with([
    'missing' => [null],
    'empty' => [''],
    'too long' => [str_repeat('a', 101)],
]);

test('regenerating the enrollment token replaces the hash and keeps the agent token', function (): void {
    $server = Server::factory()->enrolled()->create();
    $tokenHash = $server->token_hash;
    $this->actingAs(User::factory()->admin()->create());

    $this->from(route('admin.servers.show', $server))
        ->post(route('admin.servers.enrollment.regenerate', $server))
        ->assertRedirect(route('admin.servers.show', $server))
        ->assertSessionHas('inertia.flash_data.toast.message', 'New enrollment token created.');

    $flash = session('inertia.flash_data.enrollment');
    $server->refresh();

    expect(hash('sha256', $flash['token']))->toBe($server->enrollment_token_hash)
        ->and($server->enrollmentIsUsable())->toBeTrue()
        ->and($server->token_hash)->toBe($tokenHash);

    $activityLog = ActivityLog::query()->where('action', 'server.enrollment_regenerated')->sole();

    expect(json_encode($activityLog->metadata, JSON_THROW_ON_ERROR))->not->toContain($flash['token']);

    $this->postJson(route('agent.report'), AgentReportFixtures::agentReportBody(), AgentReportFixtures::agentBearer($server))
        ->assertOk();
});

test('deleting a server removes it and its agent can no longer report', function (): void {
    $server = Server::factory()->enrolled()->create();
    ServerIncident::factory()->for($server)->open()->create();
    $this->actingAs(User::factory()->admin()->create());

    $this->delete(route('admin.servers.destroy', $server))
        ->assertRedirect(route('admin.servers.index'))
        ->assertSessionHas('inertia.flash_data.toast.message', 'Server removed.');

    expect(Server::query()->count())->toBe(0)
        ->and(ServerIncident::query()->count())->toBe(0)
        ->and(ActivityLog::query()->where('action', 'server.deleted')->sole()->metadata['context'])->toBe(['server_id' => $server->id]);

    $this->postJson(route('agent.report'), AgentReportFixtures::agentReportBody(), AgentReportFixtures::agentBearer($server))
        ->assertUnauthorized();
});

test('an unknown server is a 404', function (): void {
    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.servers.show', 999_999))
        ->assertNotFound();
});

test('members and viewers cannot reach server pages', function (string $role): void {
    $server = Server::factory()->create();
    $user = $role === 'member' ? User::factory()->member()->create() : User::factory()->create();

    $this->actingAs($user);

    $this->get(route('admin.servers.index'))->assertForbidden();
    $this->get(route('admin.servers.show', $server))->assertForbidden();
    $this->post(route('admin.servers.store'), ['name' => 'Turbo'])->assertForbidden();
    $this->post(route('admin.servers.enrollment.regenerate', $server))->assertForbidden();
    $this->delete(route('admin.servers.destroy', $server))->assertForbidden();

    expect(Server::query()->count())->toBe(1);
})->with(['member', 'viewer']);
