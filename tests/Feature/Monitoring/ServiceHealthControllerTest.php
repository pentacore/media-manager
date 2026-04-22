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

    ServiceConnection::factory()->seerr()->create([
        'name' => 'Seerr Prod',
        'url' => 'http://seerr.local:5055',
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
            ->where('connections.0.name', 'Seerr Prod')
            ->where('connections.0.health_status', 'healthy')
            ->where('connections.0.version', '2.0.0')
            ->where('connections.0.latest_version', '2.1.0')
            ->where('connections.0.update_available', true)
            ->missing('connections.0.disk_space')
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

    $this->actingAs($user)
        ->get(route('monitoring.service-health'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('connections.0.update_available', false)
        );
});

test('health_status defaults to "unknown" when null', function (): void {
    $user = User::factory()->create();

    ServiceConnection::factory()->seerr()->create([
        'health_status' => null,
    ]);

    $this->actingAs($user)
        ->get(route('monitoring.service-health'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('connections.0.health_status', 'unknown')
        );
});

test('initial response defers diskSpace and does not call disk-space APIs', function (): void {
    $user = User::factory()->create();

    ServiceConnection::factory()->sonarr()->create([
        'url' => 'http://sonarr.local:8989',
    ]);

    $this->actingAs($user)
        ->get(route('monitoring.service-health'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('connections', 1)
            ->missing('diskSpace')
        );

    Http::assertNothingSent();
});

test('deferred diskSpace includes disk space for Sonarr connection', function (): void {
    $user = User::factory()->create();

    $sonarr = ServiceConnection::factory()->sonarr()->create([
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
            ->loadDeferredProps(fn ($page) => $page
                ->has('diskSpace.'.$sonarr->id, 1)
                ->where(sprintf('diskSpace.%d.0.path', $sonarr->id), '/tv')
                ->where(sprintf('diskSpace.%d.0.free_space', $sonarr->id), 1000000)
            )
        );
});

test('deferred diskSpace gracefully handles disk space API failure', function (): void {
    $user = User::factory()->create();

    $radarr = ServiceConnection::factory()->radarr()->create([
        'url' => 'http://radarr.local:7878',
    ]);

    Http::fake(['radarr.local:7878/api/v3/diskspace' => Http::response('boom', 500)]);

    $this->actingAs($user)
        ->get(route('monitoring.service-health'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->loadDeferredProps(fn ($page) => $page
                ->where('diskSpace.'.$radarr->id, [])
            )
        );
});

test('non-arr services have empty diskSpace without making HTTP calls', function (): void {
    $user = User::factory()->create();

    $emby = ServiceConnection::factory()->emby()->create();
    $seerr = ServiceConnection::factory()->seerr()->create();

    $this->actingAs($user)
        ->get(route('monitoring.service-health'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('connections', 2)
            ->loadDeferredProps(fn ($page) => $page
                ->where('diskSpace.'.$emby->id, [])
                ->where('diskSpace.'.$seerr->id, [])
            )
        );
});

test('inactive connections do not trigger disk space HTTP calls', function (): void {
    $user = User::factory()->create();

    $sonarr = ServiceConnection::factory()->sonarr()->inactive()->create([
        'url' => 'http://sonarr.local:8989',
    ]);

    $this->actingAs($user)
        ->get(route('monitoring.service-health'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('connections.0.is_active', false)
            ->loadDeferredProps(fn ($page) => $page
                ->where('diskSpace.'.$sonarr->id, [])
            )
        );
});
