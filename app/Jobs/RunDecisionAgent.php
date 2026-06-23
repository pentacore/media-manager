<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Ai\Agents\DecisionAgent;
use App\Ai\Decision\DecisionRunContext;
use App\Enums\AgentDecisionStatus;
use App\Enums\UserRole;
use App\Models\AgentDecision;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Notifications\DecisionAgentActed;
use App\Providers\AIServiceProvider;
use App\Services\AiBudget\AiBudgetExceededException;
use App\Services\AiBudget\AiBudgetGuard;
use App\Settings\DecisionAgentSettings;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Throwable;

/**
 * Runs the DecisionAgent against a single inbound webhook event.
 *
 * Carries a payload snapshot rather than the WebhookEvent model: when webhook
 * capture is disabled the row is trimmed right after processing, so a
 * serialized model could vanish before this job dequeues.
 */
class RunDecisionAgent implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    /** Cap on the event payload size handed to the model, in characters. */
    private const int MAX_PAYLOAD_CHARS = 8000;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public readonly ?int $webhookEventId,
        public readonly string $service,
        public readonly string $eventType,
        public readonly array $payload,
    ) {}

    public function uniqueId(): string
    {
        return 'decision:'.($this->webhookEventId !== null
            ? (string) $this->webhookEventId
            : sha1($this->service.'|'.$this->eventType.'|'.json_encode($this->payload)));
    }

    public function handle(
        DecisionAgentSettings $settings,
        AiBudgetGuard $aiBudgetGuard,
    ): void {
        if (! AIServiceProvider::enabled() || ! $settings->enabled()) {
            return;
        }

        // The source row may already be gone (webhook capture disabled trims it
        // right after processing). Drop the FK reference in that case so neither
        // the AgentDecision nor any proposed ActionRequest violates it.
        $webhookEventId = $this->webhookEventId !== null
            && WebhookEvent::query()->whereKey($this->webhookEventId)->exists()
                ? $this->webhookEventId
                : null;

        // Dedupe: never decide the same processed event twice.
        if ($webhookEventId !== null
            && AgentDecision::query()->where('webhook_event_id', $webhookEventId)->exists()) {
            return;
        }

        try {
            $aiBudgetGuard->enforce();
        } catch (AiBudgetExceededException $budgetException) {
            $this->record($webhookEventId, AgentDecisionStatus::Failed, 'Skipped: AI budget hard cap reached. '.$budgetException->getMessage(), null);

            return;
        }

        $context = new DecisionRunContext(
            webhookEventId: $webhookEventId,
            maxActions: $settings->maxActionsPerRun(),
            sourceService: $this->service,
        );
        app()->instance(DecisionRunContext::class, $context);

        try {
            $response = (new DecisionAgent)->prompt($this->buildPrompt());
            $summary = trim($response->text) !== '' ? trim($response->text) : 'No summary produced.';
        } catch (Throwable $throwable) {
            Log::warning('RunDecisionAgent: agent run failed', [
                'webhook_event_id' => $webhookEventId,
                'service' => $this->service,
                'event_type' => $this->eventType,
                'exception' => $throwable::class,
                'message' => $throwable->getMessage(),
            ]);
            $this->record($webhookEventId, AgentDecisionStatus::Failed, 'Agent run failed: '.$throwable->getMessage(), $context);

            return;
        } finally {
            app()->forgetInstance(DecisionRunContext::class);
        }

        $status = $context->count() > 0 ? AgentDecisionStatus::Completed : AgentDecisionStatus::NoAction;
        $this->record($webhookEventId, $status, $summary, $context);
        $this->notify($context, $summary, $settings);
    }

    private function buildPrompt(): string
    {
        $json = json_encode($this->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        $json = $json === false ? '{}' : Str::limit($json, self::MAX_PAYLOAD_CHARS, "\n… (payload truncated)");

        return <<<PROMPT
A webhook event was received and needs your decision.

Service: {$this->service}
Event type: {$this->eventType}

Payload:
{$json}

Decide whether any action is warranted. Gather context with the read tools if needed, then propose actions with ProposeActionTool (or propose nothing and explain). End with a concise audit summary.
PROMPT;
    }

    private function record(?int $webhookEventId, AgentDecisionStatus $status, string $summary, ?DecisionRunContext $context): void
    {
        $attributes = [
            'service' => $this->service,
            'event_type' => $this->eventType,
            'status' => $status,
            'summary' => Str::limit($summary, 4000, ''),
            'actions_count' => $context?->count() ?? 0,
            'action_request_ids' => $context?->actionRequestIds() ?? [],
        ];

        if ($webhookEventId !== null) {
            AgentDecision::query()->updateOrCreate(
                ['webhook_event_id' => $webhookEventId],
                $attributes,
            );

            return;
        }

        AgentDecision::query()->create($attributes);
    }

    private function notify(DecisionRunContext $context, string $summary, DecisionAgentSettings $settings): void
    {
        $suggested = $context->suggestedCount();
        $acted = $context->actedCount();

        $wantSuggest = $suggested > 0 && $settings->notifyOnSuggest();
        $wantAct = $acted > 0 && $settings->notifyOnAct();

        if (! $wantSuggest && ! $wantAct) {
            return;
        }

        $disposition = match (true) {
            $wantSuggest && $wantAct => 'mixed',
            $wantAct => 'acted',
            default => 'suggested',
        };

        $admins = User::query()->where('role', UserRole::Admin)->get();
        if ($admins->isEmpty()) {
            return;
        }

        Notification::send($admins, new DecisionAgentActed(
            disposition: $disposition,
            actionCount: $context->count(),
            summary: Str::limit($summary, 500, '…'),
            eventLabel: $this->service.' '.$this->eventType,
        ));
    }
}
