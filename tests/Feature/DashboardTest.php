<?php

declare(strict_types=1);

use App\Enums\ActionRequestStatus;
use App\Models\ActionRequest;
use App\Models\ActivityLog;
use App\Models\ServiceConnection;
use App\Models\User;
use App\Models\WebhookEvent;

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
