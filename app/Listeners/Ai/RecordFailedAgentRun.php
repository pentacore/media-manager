<?php

declare(strict_types=1);

namespace App\Listeners\Ai;

use App\Ai\AiRunAttribution;
use App\Ai\TierOutcome;
use App\Enums\AiUsageKind;
use App\Services\AiUsage\RunUsageAccumulator;
use App\Services\AiUsage\UsageColumns;
use App\Services\AiUsage\UsageRecordWriter;
use App\Services\AiUsage\UsageText;
use App\Support\UpstreamErrorText;
use Illuminate\Support\Facades\Auth;
use Laravel\Ai\Events\AgentFailed;
use Laravel\Ai\Responses\Data\TextUsage;

/**
 * AgentFailed fires once, after failover is exhausted. Bill the steps that
 * completed before the failure so the budget guard sees money already spent.
 */
class RecordFailedAgentRun
{
    public function handle(AgentFailed $agentFailed): void
    {
        $runUsageAccumulator = resolve(RunUsageAccumulator::class);
        $invocationId = $agentFailed->invocationId;
        $agentPrompt = $agentFailed->prompt;

        resolve(UsageRecordWriter::class)->record([
            'invocation_id' => $invocationId,
            'kind' => AiUsageKind::Text,
            'agent_class' => $agentPrompt->agent::class,
            'tier_position' => TierOutcome::positionFor($agentPrompt->agent),
            'provider' => $runUsageAccumulator->provider($invocationId) ?? (isset($agentPrompt->provider) ? $agentPrompt->provider->name() : null),
            'model' => $runUsageAccumulator->model($invocationId) ?? ($agentPrompt->model ?? null),
            ...UsageColumns::fromText($runUsageAccumulator->usage($invocationId) ?? new TextUsage),
            'prompt_text' => UsageText::agentInput($agentPrompt),
            'parent_invocation_id' => $agentPrompt->parentInvocationId ?? null,
            'user_id' => resolve(AiRunAttribution::class)->user()?->id ?? Auth::id(),
            'status' => 'failed',
            'error_message' => UpstreamErrorText::sanitize($agentFailed->exception->getMessage(), 2000),
        ]);

        $runUsageAccumulator->forget($invocationId);
    }
}
