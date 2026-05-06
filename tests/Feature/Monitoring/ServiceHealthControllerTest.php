<?php

declare(strict_types=1);

use App\Enums\HealthStatus;
use App\Jobs\PingServiceHealth;
use App\Models\ServiceConnection;
use App\Models\User;
use App\Support\ServiceCheckBatch;
use Illuminate\Bus\PendingBatch;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
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

test('initial response defers prowlarrIndexers and does not call Prowlarr', function (): void {
    $admin = User::factory()->admin()->create();
    ServiceConnection::factory()->prowlarr()->create();

    $this->actingAs($admin)
        ->get(route('monitoring.service-health'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Monitoring/ServiceHealth')
            ->has('connections')
            ->missing('prowlarrIndexers')
        );

    Http::assertNothingSent();
});

test('deferred prowlarrIndexers populates trimmed entries for active Prowlarr connection', function (): void {
    $admin = User::factory()->admin()->create();
    $connection = ServiceConnection::factory()->prowlarr()->create([
        'url' => 'http://prowlarr.local:9696',
        'api_key' => 'test',
        'is_active' => true,
    ]);

    Http::fake([
        'prowlarr.local:9696/api/v1/indexer' => Http::response([
            ['id' => 1, 'name' => 'Demo One', 'enable' => true, 'priority' => 25, 'fields' => [['name' => 'apiKey', 'value' => 'SECRET']]],
            ['id' => 2, 'name' => 'Demo Two', 'enable' => false, 'priority' => 50],
        ]),
    ]);

    $this->actingAs($admin)
        ->get(route('monitoring.service-health'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->loadDeferredProps(fn ($page) => $page
                ->has('prowlarrIndexers.'.$connection->id, 2)
                ->where('prowlarrIndexers.'.$connection->id.'.0.name', 'Demo One')
                ->where('prowlarrIndexers.'.$connection->id.'.0.enable', true)
                ->where('prowlarrIndexers.'.$connection->id.'.1.enable', false)
                ->missing('prowlarrIndexers.'.$connection->id.'.0.fields')
                ->missing('prowlarrIndexers.'.$connection->id.'.0.priority')
            )
        );
});

test('deferred prowlarrIndexers returns empty array when Prowlarr API fails', function (): void {
    $admin = User::factory()->admin()->create();
    $connection = ServiceConnection::factory()->prowlarr()->create([
        'url' => 'http://prowlarr.local:9696',
        'api_key' => 'test',
        'is_active' => true,
    ]);

    Http::fake([
        'prowlarr.local:9696/api/v1/indexer' => Http::response([], 500),
    ]);

    $this->actingAs($admin)
        ->get(route('monitoring.service-health'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->loadDeferredProps(fn ($page) => $page
                ->where('prowlarrIndexers.'.$connection->id, [])
            )
        );
});

test('non-Prowlarr connections are absent from prowlarrIndexers map', function (): void {
    $admin = User::factory()->admin()->create();
    $sonarr = ServiceConnection::factory()->sonarr()->create();

    $this->actingAs($admin)
        ->get(route('monitoring.service-health'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->loadDeferredProps(fn ($page) => $page
                ->missing('prowlarrIndexers.'.$sonarr->id)
            )
        );
});

test('inactive Prowlarr connection is absent from prowlarrIndexers map', function (): void {
    $admin = User::factory()->admin()->create();
    $connection = ServiceConnection::factory()->prowlarr()->inactive()->create();

    $this->actingAs($admin)
        ->get(route('monitoring.service-health'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->loadDeferredProps(fn ($page) => $page
                ->missing('prowlarrIndexers.'.$connection->id)
            )
        );
});

test('disk-mode=selected filters disks to chosen paths', function (): void {
    $user = User::factory()->create();

    $connection = ServiceConnection::factory()->sonarr()->create([
        'url' => 'http://sonarr.local:8989',
        'settings' => ['disk' => ['mode' => 'selected', 'paths' => ['/movies']]],
    ]);

    Http::fake([
        'sonarr.local:8989/api/v3/diskspace' => Http::response([
            ['path' => '/movies', 'label' => 'movies', 'freeSpace' => 100, 'totalSpace' => 200],
            ['path' => '/tv', 'label' => 'tv', 'freeSpace' => 300, 'totalSpace' => 600],
        ]),
    ]);

    $this->actingAs($user)
        ->get(route('monitoring.service-health'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->loadDeferredProps(fn ($page) => $page
                ->has('diskSpace.'.$connection->id, 1)
                ->where(sprintf('diskSpace.%d.0.path', $connection->id), '/movies')
            )
        );
});

test('disk-mode=sum collapses chosen paths into a single total row', function (): void {
    $user = User::factory()->create();

    $connection = ServiceConnection::factory()->radarr()->create([
        'url' => 'http://radarr.local:7878',
        'settings' => ['disk' => ['mode' => 'sum', 'paths' => ['/movies', '/4k']]],
    ]);

    Http::fake([
        'radarr.local:7878/api/v3/diskspace' => Http::response([
            ['path' => '/movies', 'label' => 'movies', 'freeSpace' => 100, 'totalSpace' => 200],
            ['path' => '/4k', 'label' => '4k', 'freeSpace' => 50, 'totalSpace' => 400],
            ['path' => '/other', 'label' => 'other', 'freeSpace' => 999, 'totalSpace' => 1000],
        ]),
    ]);

    $this->actingAs($user)
        ->get(route('monitoring.service-health'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->loadDeferredProps(fn ($page) => $page
                ->has('diskSpace.'.$connection->id, 1)
                ->where(sprintf('diskSpace.%d.0.path', $connection->id), 'sum')
                ->where(sprintf('diskSpace.%d.0.free_space', $connection->id), 150)
                ->where(sprintf('diskSpace.%d.0.total_space', $connection->id), 600)
            )
        );
});

test('disk display=used carries used metric on each row', function (): void {
    $user = User::factory()->create();

    $connection = ServiceConnection::factory()->sonarr()->create([
        'url' => 'http://sonarr.local:8989',
        'settings' => ['disk' => [
            'mode' => 'all',
            'paths' => [],
            'display' => ['/movies' => 'used'],
        ]],
    ]);

    Http::fake([
        'sonarr.local:8989/api/v3/diskspace' => Http::response([
            ['path' => '/movies', 'label' => 'movies', 'freeSpace' => 100, 'totalSpace' => 200],
            ['path' => '/tv', 'label' => 'tv', 'freeSpace' => 300, 'totalSpace' => 600],
        ]),
    ]);

    $this->actingAs($user)
        ->get(route('monitoring.service-health'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->loadDeferredProps(fn ($page) => $page
                ->where(sprintf('diskSpace.%d.0.display', $connection->id), 'used')
                ->where(sprintf('diskSpace.%d.1.display', $connection->id), 'both')
            )
        );
});

test('disk display sum=free attaches metric to the synthetic sum row', function (): void {
    $user = User::factory()->create();

    $connection = ServiceConnection::factory()->radarr()->create([
        'url' => 'http://radarr.local:7878',
        'settings' => ['disk' => [
            'mode' => 'sum',
            'paths' => ['/movies', '/4k'],
            'display' => ['sum' => 'free'],
        ]],
    ]);

    Http::fake([
        'radarr.local:7878/api/v3/diskspace' => Http::response([
            ['path' => '/movies', 'label' => 'movies', 'freeSpace' => 100, 'totalSpace' => 200],
            ['path' => '/4k', 'label' => '4k', 'freeSpace' => 50, 'totalSpace' => 400],
        ]),
    ]);

    $this->actingAs($user)
        ->get(route('monitoring.service-health'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->loadDeferredProps(fn ($page) => $page
                ->where(sprintf('diskSpace.%d.0.path', $connection->id), 'sum')
                ->where(sprintf('diskSpace.%d.0.display', $connection->id), 'free')
                ->where(sprintf('diskSpace.%d.0.free_space', $connection->id), 150)
            )
        );
});

test('runChecks dispatches a service-health batch for active connections', function (): void {
    Bus::fake();

    $user = User::factory()->create();
    ServiceConnection::factory()->sonarr()->create();
    ServiceConnection::factory()->radarr()->create();
    ServiceConnection::factory()->emby()->inactive()->create();

    $this->actingAs($user)
        ->post(route('monitoring.service-health.run-checks'))
        ->assertRedirect();

    Bus::assertBatched(fn (PendingBatch $pendingBatch): bool => $pendingBatch->name === 'service-health'
        && $pendingBatch->jobs->count() === 2
        && $pendingBatch->jobs->every(fn (object $job): bool => $job instanceof PingServiceHealth));

    expect(Cache::get(ServiceCheckBatch::CACHE_KEY_HEALTH))->not->toBeNull();
});

test('runChecks does nothing when there are no active connections', function (): void {
    Bus::fake();

    $user = User::factory()->create();
    ServiceConnection::factory()->sonarr()->inactive()->create();

    $this->actingAs($user)
        ->post(route('monitoring.service-health.run-checks'))
        ->assertRedirect();

    Bus::assertNothingBatched();
});
