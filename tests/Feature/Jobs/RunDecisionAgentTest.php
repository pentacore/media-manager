<?php

declare(strict_types=1);

use App\Ai\Agents\DecisionAgent;
use App\Enums\AgentDecisionStatus;
use App\Jobs\RunDecisionAgent;
use App\Models\AgentDecision;
use App\Models\WebhookEvent;
use App\Services\AiBudget\AiBudgetExceededException;
use App\Services\AiBudget\AiBudgetGuard;
use App\Settings\DecisionAgentSettings;

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

    runJob($first->id, payload: $payload);
    runJob($second->id, payload: $payload);

    expect(AgentDecision::count())->toBe(1)
        ->and(AgentDecision::first()->webhook_event_id)->toBe($first->id);
});

test('events about different subjects are not throttled by each other', function (): void {
    DecisionAgent::fake(['summary one', 'summary two']);
    $first = WebhookEvent::factory()->create();
    $second = WebhookEvent::factory()->create();

    runJob($first->id, payload: ['eventType' => 'Grab', 'series' => ['id' => 42]]);
    runJob($second->id, payload: ['eventType' => 'Grab', 'series' => ['id' => 43]]);

    expect(AgentDecision::count())->toBe(2);
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
