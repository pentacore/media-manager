<?php

declare(strict_types=1);

namespace App\Listeners\Ai;

use App\Ai\AiRunAttribution;
use App\Ai\Benchmark\BenchmarkRunContext;
use App\Ai\FailureCode;
use App\Ai\StoppedByUser;
use App\Ai\TierOutcome;
use App\Enums\AiUsageKind;
use App\Services\AiUsage\RunUsageAccumulator;
use App\Services\AiUsage\TelemetryGuard;
use App\Services\AiUsage\UsageColumns;
use App\Services\AiUsage\UsageRecordWriter;
use App\Services\AiUsage\UsageText;
use App\Support\UpstreamErrorText;
use Illuminate\Support\Facades\Auth;
use Laravel\Ai\Events\AgentFailed;
use Laravel\Ai\Responses\Data\TextUsage;
use ReflectionProperty;

/**
 * AgentFailed fires once, after failover is exhausted. Bill the steps that
 * completed before the failure so the budget guard sees money already spent.
 * A run a user stop ended is billed the same way but recorded as stopped,
 * without an error message.
 */
class RecordFailedAgentRun
{
    public function handle(AgentFailed $agentFailed): void
    {
        TelemetryGuard::run(function () use ($agentFailed): void {
            $stopped = StoppedByUser::exception($agentFailed->exception);
            $runUsageAccumulator = resolve(RunUsageAccumulator::class);
            $invocationId = $agentFailed->invocationId;
            $agentPrompt = $agentFailed->prompt;
            $benchmarkRunContext = resolve(BenchmarkRunContext::class);
            $benchmarkRunId = $benchmarkRunContext->billedRunId();

            resolve(UsageRecordWriter::class)->record([
                'invocation_id' => $invocationId,
                'kind' => AiUsageKind::Text,
                'agent_class' => $agentPrompt->agent::class,
                'tier_position' => TierOutcome::positionFor($agentPrompt->agent),
                'tier_via' => TierOutcome::viaFor($agentPrompt->agent),
                'provider' => $runUsageAccumulator->provider($invocationId) ?? ($this->initialized($agentPrompt, 'provider') ? $agentPrompt->provider->name() : null),
                'model' => $runUsageAccumulator->model($invocationId) ?? ($this->initialized($agentPrompt, 'model') ? $agentPrompt->model : null),
                ...UsageColumns::fromText($runUsageAccumulator->usage($invocationId) ?? new TextUsage),
                'prompt_text' => UsageText::agentInput($agentPrompt),
                'parent_invocation_id' => $agentPrompt->parentInvocationId ?? null,
                'benchmark_run_id' => $benchmarkRunId,
                'is_benchmark' => $benchmarkRunContext->active(),
                'user_id' => resolve(AiRunAttribution::class)->user()->id ?? Auth::id(),
                'status' => $stopped ? 'stopped' : 'failed',
                'error_message' => $stopped ? null : UpstreamErrorText::sanitize($agentFailed->exception->getMessage(), 2000, UpstreamErrorText::AGENT_EMPTY_FALLBACK),
                'error_code' => $stopped ? null : FailureCode::for($agentFailed->exception),
                'failed_step' => $runUsageAccumulator->failedStep($invocationId),
            ]);
        });

        resolve(RunUsageAccumulator::class)->forget($agentFailed->invocationId);
    }

    /**
     * A run can fail before the SDK resolved its provider, leaving these typed
     * Prompt properties uninitialized; isset() would be flagged as always true.
     */
    private function initialized(object $prompt, string $property): bool
    {
        return new ReflectionProperty($prompt, $property)->isInitialized($prompt);
    }
}
