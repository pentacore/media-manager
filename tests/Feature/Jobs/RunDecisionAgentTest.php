<?php

declare(strict_types=1);

use App\Ai\Agents\DecisionAgent;
use App\Ai\Decision\DecisionRunContext;
use App\Enums\AgentDecisionStatus;
use App\Jobs\RunDecisionAgent;
use App\Models\ActionRequest;
use App\Models\AgentDecision;
use App\Models\ServiceConnection;
use App\Models\WebhookEvent;
use App\Services\AiBudget\AiBudgetExceededException;
use App\Services\AiBudget\AiBudgetGuard;
use App\Settings\DecisionAgentSettings;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\UniqueFor;

beforeEach(function (): void {
    config(['mediamanager.ai.enabled' => true]);
    resolve(DecisionAgentSettings::class)->setEnabled(true);
});

function runJob(?int $webhookEventId, string $service = 'sonarr', string $eventType = 'ManualInteractionRequired', array $payload = ['eventType' => 'ManualInteractionRequired']): void
{
    app()->call([new RunDecisionAgent($webhookEventId, $service, $eventType, $payload), 'handle']);
}

test('records a NoAction decision when the agent proposes nothing', function (): void {
    DecisionAgent::fake(['Nothing actionable here — the import will clear on the next pass.']);
    $event = WebhookEvent::factory()->create();

    runJob($event->id);

    $decision = AgentDecision::firstWhere('webhook_event_id', $event->id);
    expect($decision)->not->toBeNull();
    expect($decision->status)->toBe(AgentDecisionStatus::NoAction);
    expect($decision->actions_count)->toBe(0);
    expect($decision->summary)->toContain('Nothing actionable');
});

test('is idempotent — a second run for the same event does nothing', function (): void {
    DecisionAgent::fake(['summary']);
    $event = WebhookEvent::factory()->create();
    AgentDecision::factory()->create(['webhook_event_id' => $event->id]);

    runJob($event->id);

    expect(AgentDecision::where('webhook_event_id', $event->id)->count())->toBe(1);
});

test('a second event about the same subject inside the cooldown is skipped', function (): void {
    DecisionAgent::fake(['summary one', 'summary two']);
    $first = WebhookEvent::factory()->create();
    $second = WebhookEvent::factory()->create();
    $payload = ['eventType' => 'Grab', 'series' => ['id' => 42]];

    runJob($first->id, eventType: 'Grab', payload: $payload);
    runJob($second->id, eventType: 'Grab', payload: $payload);

    expect(AgentDecision::count())->toBe(1)
        ->and(AgentDecision::first()->webhook_event_id)->toBe($first->id);
});

test('events about different subjects are not throttled by each other', function (): void {
    DecisionAgent::fake(['summary one', 'summary two']);
    $first = WebhookEvent::factory()->create();
    $second = WebhookEvent::factory()->create();

    runJob($first->id, eventType: 'Grab', payload: ['eventType' => 'Grab', 'series' => ['id' => 42]]);
    runJob($second->id, eventType: 'Grab', payload: ['eventType' => 'Grab', 'series' => ['id' => 43]]);

    expect(AgentDecision::count())->toBe(2);
});

test('a Grab cooldown for a series does not block a later stuck import for the same series', function (): void {
    DecisionAgent::fake(['grab summary', 'stuck import summary']);
    $first = WebhookEvent::factory()->create();
    $second = WebhookEvent::factory()->create();

    runJob($first->id, eventType: 'Grab', payload: ['eventType' => 'Grab', 'series' => ['id' => 42]]);
    runJob($second->id, eventType: 'ManualInteractionRequired', payload: [
        'eventType' => 'ManualInteractionRequired',
        'series' => ['id' => 42],
        'downloadId' => 'abc123',
    ]);

    expect(AgentDecision::count())->toBe(2);
});

test('two stuck imports for the same download inside the cooldown are throttled', function (): void {
    DecisionAgent::fake(['summary one', 'summary two']);
    $first = WebhookEvent::factory()->create();
    $second = WebhookEvent::factory()->create();
    $payload = ['eventType' => 'ManualInteractionRequired', 'series' => ['id' => 42], 'downloadId' => 'abc123'];

    runJob($first->id, eventType: 'ManualInteractionRequired', payload: $payload);
    runJob($second->id, eventType: 'ManualInteractionRequired', payload: $payload);

    expect(AgentDecision::count())->toBe(1)
        ->and(AgentDecision::first()->webhook_event_id)->toBe($first->id);
});

test('two stuck imports for the same downloadInfo.downloadId inside the cooldown are throttled', function (): void {
    DecisionAgent::fake(['summary one', 'summary two']);
    $first = WebhookEvent::factory()->create();
    $second = WebhookEvent::factory()->create();
    $payload = [
        'eventType' => 'ManualInteractionRequired',
        'series' => ['id' => 42],
        'downloadInfo' => ['downloadId' => 'xyz789'],
    ];

    runJob($first->id, eventType: 'ManualInteractionRequired', payload: $payload);
    runJob($second->id, eventType: 'ManualInteractionRequired', payload: $payload);

    expect(AgentDecision::count())->toBe(1)
        ->and(AgentDecision::first()->webhook_event_id)->toBe($first->id);
});

test('does not run when the agent is disabled', function (): void {
    resolve(DecisionAgentSettings::class)->setEnabled(false);
    $event = WebhookEvent::factory()->create();

    runJob($event->id);

    expect(AgentDecision::count())->toBe(0);
});

test('records a Failed decision when the budget hard cap is hit', function (): void {
    $this->mock(AiBudgetGuard::class)
        ->shouldReceive('enforce')
        ->andThrow(new AiBudgetExceededException(10.0, 5.0));

    $event = WebhookEvent::factory()->create();
    runJob($event->id);

    $decision = AgentDecision::firstWhere('webhook_event_id', $event->id);
    expect($decision->status)->toBe(AgentDecisionStatus::Failed);
    expect($decision->summary)->toContain('budget');
});

test('uniqueId is stable per webhook event', function (): void {
    $a = new RunDecisionAgent(42, 'sonarr', 'X', []);
    $b = new RunDecisionAgent(42, 'sonarr', 'X', []);

    expect($a->uniqueId())->toBe($b->uniqueId());
    expect($a->uniqueId())->toBe('decision:42');
});

test('job has unique lock timeout and unique-for duration', function (): void {
    $job = new RunDecisionAgent(null, 'sonarr', 'test', []);
    $reflection = new ReflectionClass($job);

    expect($reflection->getAttributes(Timeout::class)[0]->newInstance()->timeout)->toBe(240)
        ->and($reflection->getAttributes(UniqueFor::class)[0]->newInstance()->uniqueFor)->toBe(600);
});

test('a trimmed event still hands its payload snapshot and connection to the decision run', function (): void {
    $connection = ServiceConnection::factory()->sonarr()->create();
    $payload = ['eventType' => 'ManualInteractionRequired', 'series' => ['id' => 42], 'downloadId' => 'dl-1'];
    $captured = null;
    DecisionAgent::fake([function () use (&$captured): string {
        $captured = resolve(DecisionRunContext::class);

        return 'summary';
    }]);

    app()->call([new RunDecisionAgent(
        webhookEventId: 987654,
        service: 'sonarr',
        eventType: 'ManualInteractionRequired',
        payload: $payload,
        serviceConnectionId: $connection->id,
    ), 'handle']);

    expect($captured)->toBeInstanceOf(DecisionRunContext::class)
        ->and($captured->webhookEventId)->toBeNull()
        ->and($captured->eventPayload)->toBe($payload)
        ->and($captured->originConnectionId)->toBe($connection->id);
});

test('a persisted event without a carried connection falls back to the event row connection', function (): void {
    $connection = ServiceConnection::factory()->sonarr()->create();
    $event = WebhookEvent::factory()->for($connection, 'serviceConnection')->create();
    $captured = null;
    DecisionAgent::fake([function () use (&$captured): string {
        $captured = resolve(DecisionRunContext::class);

        return 'summary';
    }]);

    runJob($event->id);

    expect($captured->webhookEventId)->toBe($event->id)
        ->and($captured->originConnectionId)->toBe($connection->id);
});

test('a run the worker stops leaves a Failed decision with the reason', function (): void {
    $event = WebhookEvent::factory()->create();

    (new RunDecisionAgent($event->id, 'sonarr', 'Grab', ['eventType' => 'Grab', 'series' => ['id' => 42]]))
        ->failed(new RuntimeException('App\Jobs\RunDecisionAgent has timed out.'));

    expect(AgentDecision::query()->where('webhook_event_id', $event->id)->sole())
        ->status->toBe(AgentDecisionStatus::Failed)
        ->service->toBe('sonarr')
        ->event_type->toBe('Grab')
        ->summary->toBe('Agent run stopped by the worker: App\Jobs\RunDecisionAgent has timed out.')
        ->actions_count->toBe(0)
        ->action_request_ids->toBe([]);
});

test('a stopped run links the actions it queued before the worker stopped it', function (): void {
    $event = WebhookEvent::factory()->create();
    $actionRequest = ActionRequest::factory()->create(['webhook_event_id' => $event->id]);

    (new RunDecisionAgent($event->id, 'sonarr', 'ManualInteractionRequired', ['downloadId' => 'dl-1']))
        ->failed(new RuntimeException('killed'));

    expect(AgentDecision::query()->where('webhook_event_id', $event->id)->sole())
        ->status->toBe(AgentDecisionStatus::Failed)
        ->actions_count->toBe(1)
        ->action_request_ids->toBe([$actionRequest->id]);
});

test('a run that already recorded its outcome is left alone by the failed callback', function (): void {
    $event = WebhookEvent::factory()->create();
    AgentDecision::factory()->create([
        'webhook_event_id' => $event->id,
        'status' => AgentDecisionStatus::NoAction,
        'summary' => 'Nothing to do.',
    ]);

    (new RunDecisionAgent($event->id, 'sonarr', 'Grab', []))->failed(new RuntimeException('late failure'));

    expect(AgentDecision::query()->where('webhook_event_id', $event->id)->sole())
        ->status->toBe(AgentDecisionStatus::NoAction)
        ->summary->toBe('Nothing to do.');
});

test('a stopped run for a trimmed event still records a Failed decision', function (): void {
    (new RunDecisionAgent(987654, 'radarr', 'Grab', ['movie' => ['id' => 5]]))->failed(null);

    expect(AgentDecision::query()->sole())
        ->webhook_event_id->toBeNull()
        ->service->toBe('radarr')
        ->status->toBe(AgentDecisionStatus::Failed)
        ->summary->toBe('Agent run stopped by the worker: no reason given.');
});
