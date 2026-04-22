<?php

declare(strict_types=1);

use App\Enums\ActionRequestStatus;
use App\Models\ActionRequest;
use App\Models\ActivityLog;
use App\Models\ServiceConnection;
use App\Models\User;
use App\Models\WebhookEvent;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config()->set('inertia.ssr.enabled', false);
    config()->set('inertia.testing.ensure_pages_exist', false);
    Http::preventStrayRequests();
});

test('guests are redirected to the login page', function (): void {
    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('authenticated users can visit the dashboard', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    $response = $this->get(route('dashboard'));
    $response->assertOk();
});

test('dashboard returns correct stat counts', function (): void {
    $user = User::factory()->create();

    $activeConnections = ServiceConnection::factory()->count(3)->create(['is_active' => true]);
    $inactiveConnection = ServiceConnection::factory()->inactive()->create();

    WebhookEvent::factory()->count(5)->create([
        'service_connection_id' => $activeConnections->first()->id,
        'created_at' => now()->subHours(12),
    ]);
    WebhookEvent::factory()->count(2)->create([
        'service_connection_id' => $activeConnections->first()->id,
        'created_at' => now()->subDays(2),
    ]);

    $webhookEvent = WebhookEvent::factory()->create([
        'service_connection_id' => $activeConnections->first()->id,
    ]);
    ActionRequest::factory()->count(2)->create([
        'webhook_event_id' => $webhookEvent->id,
        'status' => ActionRequestStatus::Pending,
    ]);
    ActionRequest::factory()->completed()->create([
        'webhook_event_id' => $webhookEvent->id,
    ]);

    $response = $this->actingAs($user)->get(route('dashboard'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('Dashboard')
        ->has('stats')
        ->where('stats.activeServices', 3)
        ->where('stats.totalServices', 4)
        ->where('stats.recentWebhooks', 6)
        ->where('stats.pendingActions', 2)
    );
});

test('dashboard includes recent activity', function (): void {
    $user = User::factory()->create();
    ActivityLog::factory()->count(3)->create(['user_id' => $user->id]);

    $response = $this->actingAs($user)->get(route('dashboard'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('Dashboard')
        ->has('recentActivity', 3)
        ->has('recentActivity.0', fn ($activity) => $activity
            ->has('id')
            ->has('action')
            ->has('description')
            ->has('user_name')
            ->has('service_name')
            ->has('created_at')
            ->has('service_type')
        )
    );
});

test('dashboard includes recent webhook events', function (): void {
    $user = User::factory()->create();
    $connection = ServiceConnection::factory()->create();
    WebhookEvent::factory()->count(3)->create(['service_connection_id' => $connection->id]);

    $response = $this->actingAs($user)->get(route('dashboard'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('Dashboard')
        ->has('recentWebhookEvents', 3)
        ->has('recentWebhookEvents.0', fn ($event) => $event
            ->has('id')
            ->has('event_type')
            ->has('service_name')
            ->has('service_type')
            ->has('processed')
            ->has('created_at')
        )
    );
});

test('dashboard shell omits deferred nowPlaying prop on initial render', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Dashboard')
            ->missing('nowPlaying')
        );
});

test('dashboard deferred nowPlaying resolves to empty array without an active emby connection', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Dashboard')
            ->loadDeferredProps(fn ($page) => $page
                ->where('nowPlaying', [])
            )
        );
});

test('dashboard deferred nowPlaying maps live emby sessions', function (): void {
    $user = User::factory()->create();
    ServiceConnection::factory()->emby()->create([
        'url' => 'http://emby.local:8096',
        'api_key' => 'test-api-key',
    ]);

    Http::fake([
        'emby.local:8096/Sessions' => Http::response([
            [
                'Id' => 'session-1',
                'UserName' => 'alice',
                'NowPlayingItem' => [
                    'Name' => 'Pilot',
                    'Type' => 'Episode',
                    'SeriesName' => 'My Show',
                    'RunTimeTicks' => 12000000000,
                ],
                'PlayState' => ['PositionTicks' => 5000000000, 'IsPaused' => false],
            ],
            [
                'Id' => 'session-2',
                'UserName' => 'bob',
                'NowPlayingItem' => [
                    'Name' => 'The Matrix',
                    'Type' => 'Movie',
                    'RunTimeTicks' => 8000000000,
                ],
                'PlayState' => ['PositionTicks' => 1000000000, 'IsPaused' => true],
            ],
            [
                'Id' => 'session-3',
                'UserName' => 'carol',
            ],
        ]),
    ]);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Dashboard')
            ->loadDeferredProps(fn ($page) => $page
                ->has('nowPlaying', 2)
                ->where('nowPlaying.0.emby_username', 'alice')
                ->where('nowPlaying.0.media_title', 'Pilot')
                ->where('nowPlaying.0.series_title', 'My Show')
                ->where('nowPlaying.0.media_type', 'episode')
                ->where('nowPlaying.0.action', 'playing')
                ->where('nowPlaying.1.emby_username', 'bob')
                ->where('nowPlaying.1.media_type', 'movie')
                ->where('nowPlaying.1.action', 'paused')
            )
        );
});

test('dashboard deferred nowPlaying falls back to empty array on emby http failure', function (): void {
    $user = User::factory()->create();
    ServiceConnection::factory()->emby()->create([
        'url' => 'http://emby.local:8096',
        'api_key' => 'test-api-key',
    ]);

    Http::fake(fn () => Http::response('Service Unavailable', 503));

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Dashboard')
            ->loadDeferredProps(fn ($page) => $page
                ->where('nowPlaying', [])
            )
        );
});
