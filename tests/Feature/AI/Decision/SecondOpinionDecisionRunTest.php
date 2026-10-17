<?php

declare(strict_types=1);

use App\Ai\Agents\DecisionAgent;
use App\Ai\Agents\DecisionVerifierAgent;
use App\Ai\Decision\DecisionRunContext;
use App\Ai\Tools\Decision\ProposeActionTool;
use App\Enums\ActionRequestStatus;
use App\Enums\SecondOpinionMode;
use App\Jobs\RunDecisionAgent;
use App\Models\ActionRequest;
use App\Models\ActionTypeConfig;
use App\Models\AgentDecision;
use App\Models\AiUsageRecord;
use App\Models\ServiceConnection;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Notifications\DecisionAgentActed;
use App\Settings\DecisionAgentSettings;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Tools\Request;

beforeEach(function (): void {
    Http::preventStrayRequests();
    Queue::fake();
    config(['mediamanager.ai.enabled' => true]);
    resolve(DecisionAgentSettings::class)->setEnabled(true);
});

afterEach(function (): void {
    app()->forgetInstance(DecisionRunContext::class);
});

function secondOpinionSeriesEvent(): WebhookEvent
{
    return WebhookEvent::factory()
        ->for(ServiceConnection::factory()->sonarr(), 'serviceConnection')
        ->create(['payload' => ['series' => ['id' => 7]]]);
}

/**
 * @return array{type: string, target_service: string, rationale: string, payload: array<string, int>}
 */
function secondOpinionDeleteProposal(): array
{
    return ['type' => 'delete_series', 'target_service' => 'sonarr', 'rationale' => 'Unmonitored and unwatched.', 'payload' => ['sonarr_series_id' => 7]];
}

function secondOpinionDeleteRule(SecondOpinionMode $secondOpinionMode): void
{
    ActionTypeConfig::factory()->create(['type' => 'delete_series', 'requires_approval' => true, 'is_enabled' => true, 'second_opinion' => $secondOpinionMode]);
}

function secondOpinionToolContext(WebhookEvent $webhookEvent, int $maxActions = 3): DecisionRunContext
{
    $decisionRunContext = new DecisionRunContext(
        webhookEventId: $webhookEvent->id,
        maxActions: $maxActions,
        sourceService: 'sonarr',
        eventPayload: $webhookEvent->payload,
        originConnectionId: $webhookEvent->service_connection_id,
        eventType: 'SeriesDelete',
    );
    app()->instance(DecisionRunContext::class, $decisionRunContext);

    return $decisionRunContext;
}

test('the proposer learns its action was rejected but not why', function (): void {
    secondOpinionDeleteRule(SecondOpinionMode::Reject);
    $decisionRunContext = secondOpinionToolContext(secondOpinionSeriesEvent());
    DecisionVerifierAgent::fake([['agrees' => false, 'reason' => 'SECRET-REASON']]);

    $result = (new ProposeActionTool)->handle(new Request(secondOpinionDeleteProposal()));

    expect(json_decode($result, true))->toMatchArray(['queued' => false, 'reason' => 'second_opinion_rejected'])
        ->and($result)->not->toContain('SECRET-REASON')
        ->and(ActionRequest::query()->sole()->status)->toBe(ActionRequestStatus::Rejected)
        ->and($decisionRunContext->rejectedCount())->toBe(1);
});

test('the verifier sees the triggering event', function (): void {
    secondOpinionDeleteRule(SecondOpinionMode::Hold);
    secondOpinionToolContext(secondOpinionSeriesEvent());
    DecisionVerifierAgent::fake([['agrees' => true, 'reason' => 'Fine.']]);

    (new ProposeActionTool)->handle(new Request(secondOpinionDeleteProposal()));

    DecisionVerifierAgent::assertPrompted(fn ($prompt): bool => str_contains($prompt->prompt, 'Event type: SeriesDelete')
        && str_contains($prompt->prompt, '"id": 7'));
});

test('a rejected proposal uses up the per-run cap', function (): void {
    secondOpinionDeleteRule(SecondOpinionMode::Reject);
    secondOpinionToolContext(secondOpinionSeriesEvent(), maxActions: 1);
    DecisionVerifierAgent::fake([['agrees' => false, 'reason' => 'No.']]);
    $proposeActionTool = new ProposeActionTool;

    $proposeActionTool->handle(new Request(secondOpinionDeleteProposal()));

    $second = json_decode($proposeActionTool->handle(new Request(secondOpinionDeleteProposal())), true);

    expect($second['reason'])->toBe('max_actions_reached')
        ->and(ActionRequest::query()->count())->toBe(1);
});

test('the verifier usage row is a child of the decision run', function (): void {
    secondOpinionDeleteRule(SecondOpinionMode::Hold);
    DecisionVerifierAgent::fake([['agrees' => true, 'reason' => 'Fine.']]);
    DecisionAgent::fake([
        new ToolCall(id: 'c1', name: 'ProposeActionTool', arguments: secondOpinionDeleteProposal()),
        'Queued a deletion for approval.',
    ]);
    $webhookEvent = secondOpinionSeriesEvent();

    app()->call([new RunDecisionAgent($webhookEvent->id, 'sonarr', 'SeriesDelete', $webhookEvent->payload, $webhookEvent->service_connection_id), 'handle']);

    $agentDecision = AgentDecision::query()->sole();
    expect($agentDecision->action_request_ids)->toHaveCount(1)
        ->and($agentDecision->invocation_id)->not->toBeNull()
        ->and(AiUsageRecord::query()->where('agent_class', DecisionVerifierAgent::class)->sole()->parent_invocation_id)
        ->toBe($agentDecision->invocation_id);
});

test('a verifier crash inside a run still queues the proposal for approval', function (): void {
    secondOpinionDeleteRule(SecondOpinionMode::Reject);
    DecisionVerifierAgent::fake(fn () => throw new RuntimeException('boom'));
    DecisionAgent::fake([
        new ToolCall(id: 'c1', name: 'ProposeActionTool', arguments: secondOpinionDeleteProposal()),
        'Queued a deletion for approval.',
    ]);
    $webhookEvent = secondOpinionSeriesEvent();

    app()->call([new RunDecisionAgent($webhookEvent->id, 'sonarr', 'SeriesDelete', $webhookEvent->payload, $webhookEvent->service_connection_id), 'handle']);

    $actionRequest = ActionRequest::query()->sole();
    expect($actionRequest->status)->toBe(ActionRequestStatus::Pending)
        ->and($actionRequest->second_opinion['failed'])->toBeTrue();
});

test('the run notification counts only queued actions and names the held and rejected ones', function (): void {
    Notification::fake();
    $admin = User::factory()->admin()->create();
    secondOpinionDeleteRule(SecondOpinionMode::Reject);
    ActionTypeConfig::factory()->create(['type' => 'monitor_series', 'requires_approval' => false, 'is_enabled' => true, 'second_opinion' => SecondOpinionMode::Hold]);
    DecisionVerifierAgent::fake([
        ['agrees' => false, 'reason' => 'Already monitored.'],
        ['agrees' => false, 'reason' => 'Destructive without cause.'],
    ]);
    DecisionAgent::fake([
        new ToolCall(id: 'c1', name: 'ProposeActionTool', arguments: ['type' => 'monitor_series', 'target_service' => 'sonarr', 'rationale' => 'It was unmonitored.', 'payload' => ['series_id' => 7, 'monitored' => true]]),
        new ToolCall(id: 'c2', name: 'ProposeActionTool', arguments: secondOpinionDeleteProposal()),
        'Queued a monitor; the deletion was rejected.',
    ]);
    $webhookEvent = secondOpinionSeriesEvent();

    app()->call([new RunDecisionAgent($webhookEvent->id, 'sonarr', 'SeriesDelete', $webhookEvent->payload, $webhookEvent->service_connection_id), 'handle']);

    expect(ActionRequest::query()->pluck('status', 'type')->all())
        ->toBe(['monitor_series' => ActionRequestStatus::Pending, 'delete_series' => ActionRequestStatus::Rejected]);
    Notification::assertSentTo($admin, DecisionAgentActed::class, fn (DecisionAgentActed $decisionAgentActed): bool => $decisionAgentActed->actionCount === 1
        && $decisionAgentActed->heldBySecondOpinion === 1
        && $decisionAgentActed->rejectedBySecondOpinion === 1);
});

test('a run gives its proposals a deadline inside the job timeout', function (): void {
    $this->freezeTime();
    $deadline = null;
    DecisionAgent::fake([function () use (&$deadline): string {
        $deadline = resolve(DecisionRunContext::class)->deadline;

        return 'Nothing to do.';
    }]);
    $webhookEvent = secondOpinionSeriesEvent();

    app()->call([new RunDecisionAgent($webhookEvent->id, 'sonarr', 'SeriesDelete', $webhookEvent->payload, $webhookEvent->service_connection_id), 'handle']);

    expect($deadline?->toIso8601String())->toBe(now()->addSeconds(RunDecisionAgent::TIMEOUT_SECONDS - RunDecisionAgent::RUN_DEADLINE_RESERVE_SECONDS)->toIso8601String());
});

test('a proposal made late in a run is held without asking the verifier', function (): void {
    $this->freezeTime();
    secondOpinionDeleteRule(SecondOpinionMode::Reject);
    DecisionVerifierAgent::fake()->preventStrayPrompts();
    DecisionAgent::fake([
        function (): ToolCall {
            $this->travel(RunDecisionAgent::TIMEOUT_SECONDS - RunDecisionAgent::RUN_DEADLINE_RESERVE_SECONDS - 30)->seconds();

            return new ToolCall(id: 'c1', name: 'ProposeActionTool', arguments: secondOpinionDeleteProposal());
        },
        'Queued a deletion for approval.',
    ]);
    $webhookEvent = secondOpinionSeriesEvent();

    app()->call([new RunDecisionAgent($webhookEvent->id, 'sonarr', 'SeriesDelete', $webhookEvent->payload, $webhookEvent->service_connection_id), 'handle']);

    $actionRequest = ActionRequest::query()->sole();
    expect($actionRequest->status)->toBe(ActionRequestStatus::Pending)
        ->and($actionRequest->second_opinion)->toMatchArray(['failed' => true, 'reason' => 'Skipped: the decision run is out of time.'])
        ->and(AgentDecision::query()->sole()->summary)->toBe('Queued a deletion for approval.');
    DecisionVerifierAgent::assertNeverPrompted();
});
