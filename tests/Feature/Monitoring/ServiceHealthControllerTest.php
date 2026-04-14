<?php

declare(strict_types=1);

use App\Enums\HealthStatus;
use App\Models\ServiceConnection;
use App\Models\User;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config()->set('inertia.ssr.enabled', false);
    config()->set('inertia.testing.ensure_pages_exist', false);
    Http::preventStrayRequests();
});

test('guests are redirected to login', function (): void {
    $this->get(route('monitoring.service-health'))->assertRedirect(route('login'));
});

test('viewers can access service health page', function (): void {
    $viewer = User::factory()->create();

    $this->actingAs($viewer)
        ->get(route('monitoring.service-health'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Monitoring/ServiceHealth'));
});

test('members and admins can access service health page', function (): void {
    $member = User::factory()->member()->create();
    $this->actingAs($member)->get(route('monitoring.service-health'))->assertOk();

    $admin = User::factory()->admin()->create();
    $this->actingAs($admin)->get(route('monitoring.service-health'))->assertOk();
});

test('renders persisted connection state including health_status and version info', function (): void {
    $user = User::factory()->create();

    ServiceConnection::factory()->jellyseerr()->create([
        'name' => 'Jellyseerr Prod',
        'url' => 'http://jellyseerr.local:5055',
        'version' => '2.0.0',
        'latest_version' => '2.1.0',
        'health_status' => HealthStatus::Healthy,
        'last_seen_at' => now()->subMinute(),
    ]);

    $this->actingAs($user)
        ->get(route('monitoring.service-health'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Monitoring/ServiceHealth')
            ->has('connections', 1)
            ->where('connections.0.name', 'Jellyseerr Prod')
            ->where('connections.0.health_status', 'healthy')
            ->where('connections.0.version', '2.0.0')
            ->where('connections.0.latest_version', '2.1.0')
            ->where('connections.0.update_available', true)
        );
});

test('update_available is false when versions match', function (): void {
    $user = User::factory()->create();

    ServiceConnection::factory()->sonarr()->create([
        'url' => 'http://sonarr.local:8989',
        'version' => '4.0.0',
        'latest_version' => '4.0.0',
        'health_status' => HealthStatus::Healthy,
    ]);

    Http::fake(['sonarr.local:8989/api/v3/diskspace' => Http::response([])]);

    $this->actingAs($user)
        ->get(route('monitoring.service-health'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('connections.0.update_available', false)
        );
});

test('health_status defaults to "unknown" when null', function (): void {
    $user = User::factory()->create();

    ServiceConnection::factory()->jellyseerr()->create([
        'health_status' => null,
    ]);

    $this->actingAs($user)
        ->get(route('monitoring.service-health'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('connections.0.health_status', 'unknown')
        );
});

test('includes disk space for Sonarr connection', function (): void {
    $user = User::factory()->create();

    ServiceConnection::factory()->sonarr()->create([
        'url' => 'http://sonarr.local:8989',
    ]);

    Http::fake([
        'sonarr.local:8989/api/v3/diskspace' => Http::response([
            ['path' => '/tv', 'label' => 'TV Shows', 'freeSpace' => 1000000, 'totalSpace' => 5000000],
        ]),
    ]);

    $this->actingAs($user)
        ->get(route('monitoring.service-health'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('connections.0.disk_space', 1)
            ->where('connections.0.disk_space.0.path', '/tv')
            ->where('connections.0.disk_space.0.free_space', 1000000)
        );
});

test('gracefully handles disk space API failure', function (): void {
    $user = User::factory()->create();

    ServiceConnection::factory()->radarr()->create([
        'url' => 'http://radarr.local:7878',
    ]);

    Http::fake(['radarr.local:7878/api/v3/diskspace' => Http::response('boom', 500)]);

    $this->actingAs($user)
        ->get(route('monitoring.service-health'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('connections.0.disk_space', null)
        );
});

test('non-arr services have null disk_space without making HTTP calls', function (): void {
    $user = User::factory()->create();

    ServiceConnection::factory()->emby()->create();
    ServiceConnection::factory()->jellyseerr()->create();

    $this->actingAs($user)
        ->get(route('monitoring.service-health'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('connections', 2)
            ->where('connections.0.disk_space', null)
            ->where('connections.1.disk_space', null)
        );
});

test('inactive connections do not trigger disk space HTTP calls', function (): void {
    $user = User::factory()->create();

    ServiceConnection::factory()->sonarr()->inactive()->create([
        'url' => 'http://sonarr.local:8989',
    ]);

    $this->actingAs($user)
        ->get(route('monitoring.service-health'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('connections.0.is_active', false)
            ->where('connections.0.disk_space', null)
        );
});
