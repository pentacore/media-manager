<?php

declare(strict_types=1);

use App\Enums\AiUsageKind;
use App\Enums\WebhookHandlingStatus;
use App\Models\ActionRequest;
use App\Models\ActivityLog;
use App\Models\AgentDecision;
use App\Models\AiUsageRecord;
use App\Models\ServiceConnection;
use App\Models\User;
use App\Models\WebhookEvent;
use Inertia\Testing\AssertableInertia;

test('show exposes handling, activity, ai decision and actions', function (): void {
    $admin = User::factory()->admin()->create();
    $connection = ServiceConnection::factory()->sonarr()->create();
    $event = WebhookEvent::factory()->create([
        'service_connection_id' => $connection->id,
        'handling_status' => WebhookHandlingStatus::Handled,
    ]);
    ActivityLog::create([
        'service_connection_id' => $connection->id,
        'webhook_event_id' => $event->id,
        'action' => 'webhook.sonarr.download',
        'description' => 'Imported 1 episode.',
        'metadata' => ['series_id' => 5],
    ]);
    AgentDecision::factory()->completed(1)->create(['webhook_event_id' => $event->id, 'summary' => 'Proposed a scan.']);
    ActionRequest::factory()->create(['webhook_event_id' => $event->id, 'target_service' => 'emby']);

    $this->actingAs($admin)
        ->get(route('admin.webhook-log.show', $event))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $assertableInertia): AssertableInertia => $assertableInertia
            ->component('Admin/WebhookLog/Show')
            ->where('event.handling_status', 'handled')
            ->has('event.activity', 1)
            ->where('event.activity.0.action', 'webhook.sonarr.download')
            ->where('event.agent_decision.summary', 'Proposed a scan.')
            ->has('event.actions', 1)
            ->where('event.actions.0.target_service', 'emby')
        );
});

function decisionWithUsage(array $usage, ?string $invocationId = 'inv-decision-1'): WebhookEvent
{
    $event = WebhookEvent::factory()->create();
    AgentDecision::factory()->completed(1)->create(['webhook_event_id' => $event->id, 'invocation_id' => $invocationId]);

    if ($usage !== []) {
        AiUsageRecord::factory()->create(['invocation_id' => 'inv-decision-1', 'kind' => AiUsageKind::Text, 'prompt_text' => 'Decide.', ...$usage]);
    }

    return $event;
}

test('show exposes the capture invocation id when the run can become a case', function (): void {
    $webhookEvent = decisionWithUsage(['is_benchmark' => false]);

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.webhook-log.show', $webhookEvent))
        ->assertInertia(fn (AssertableInertia $assertableInertia): AssertableInertia => $assertableInertia
            ->where('event.agent_decision.capture_invocation_id', 'inv-decision-1'));
});

test('show hides the capture invocation id without a usage row, for benchmark rows and without an id', function (array $usage, ?string $invocationId): void {
    $webhookEvent = decisionWithUsage($usage, $invocationId);

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.webhook-log.show', $webhookEvent))
        ->assertInertia(fn (AssertableInertia $assertableInertia): AssertableInertia => $assertableInertia
            ->where('event.agent_decision.capture_invocation_id', null));
})->with([
    'no usage row' => [[], 'inv-decision-1'],
    'benchmark row' => [['is_benchmark' => true], 'inv-decision-1'],
    'no invocation id' => [[], null],
]);
