<?php

declare(strict_types=1);

use App\Enums\WebhookHandlingStatus;
use App\Models\ActivityLog;
use App\Models\AgentDecision;
use App\Models\ServiceConnection;
use App\Models\User;
use App\Models\WebhookEvent;
use Inertia\Testing\AssertableInertia;

test('index exposes handling status, counts and ai decision per row', function (): void {
    $admin = User::factory()->admin()->create();
    $connection = ServiceConnection::factory()->sonarr()->create();
    $event = WebhookEvent::factory()->create([
        'service_connection_id' => $connection->id,
        'handling_status' => WebhookHandlingStatus::Handled,
    ]);
    ActivityLog::create([
        'service_connection_id' => $connection->id,
        'webhook_event_id' => $event->id,
        'action' => 'webhook.sonarr.grab',
        'description' => 'x',
        'metadata' => [],
    ]);
    AgentDecision::factory()->completed(2)->create(['webhook_event_id' => $event->id]);

    $this->actingAs($admin)
        ->get(route('admin.webhook-log.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $assertableInertia): AssertableInertia => $assertableInertia
            ->component('Admin/WebhookLog/Index')
            ->where('events.data.0.handling_status', 'handled')
            ->where('events.data.0.activity_count', 1)
            ->where('events.data.0.agent_decision.status', 'completed')
            ->where('events.data.0.agent_decision.actions_count', 2)
            ->has('filterOptions.handlingStatuses', 5)
        );
});

test('index filters by handling status', function (): void {
    $admin = User::factory()->admin()->create();
    $connection = ServiceConnection::factory()->sonarr()->create();
    WebhookEvent::factory()->create(['service_connection_id' => $connection->id, 'handling_status' => WebhookHandlingStatus::Handled]);
    WebhookEvent::factory()->create(['service_connection_id' => $connection->id, 'handling_status' => WebhookHandlingStatus::Ignored]);

    $this->actingAs($admin)
        ->get(route('admin.webhook-log.index', ['handling_status' => 'ignored']))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $assertableInertia): AssertableInertia => $assertableInertia
            ->has('events.data', 1)
            ->where('events.data.0.handling_status', 'ignored')
        );
});
