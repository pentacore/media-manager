<?php

declare(strict_types=1);

namespace App\Listeners\Ai;

use App\Ai\AiRunAttribution;
use App\Enums\AiUsageKind;
use App\Services\AiUsage\RunUsageAccumulator;
use App\Services\AiUsage\UsageColumns;
use App\Services\AiUsage\UsageRecordWriter;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Laravel\Ai\Events\AgentFailedOver;
use Laravel\Ai\Gateway\ParentInvocation;

/**
 * Failover reruns the whole prompt on the next provider under the same
 * invocation id, and the final success/failure row bills only that last
 * attempt. Bill the steps the abandoned provider already completed here, at
 * that provider's price, then clear the accumulator so the next attempt
 * starts from zero.
 */
class RecordFailedOverAttemptUsage
{
    public function handle(AgentFailedOver $agentFailedOver): void
    {
        $runUsageAccumulator = resolve(RunUsageAccumulator::class);
        $invocationId = $agentFailedOver->invocationId;
        $textUsage = $runUsageAccumulator->usage($invocationId);

        if ($textUsage !== null) {
            $provider = $runUsageAccumulator->provider($invocationId) ?? $agentFailedOver->provider->name();
            $model = $runUsageAccumulator->model($invocationId) ?? $agentFailedOver->model;

            resolve(UsageRecordWriter::class)->record([
                // The run's own id stays free for the attempt that ends it.
                'invocation_id' => sprintf('%s:failover:%s:%s', $invocationId, $provider, $model),
                'kind' => AiUsageKind::Text,
                'agent_class' => $agentFailedOver->agent::class,
                'provider' => $provider,
                'model' => $model,
                ...UsageColumns::fromText($textUsage),
                'parent_invocation_id' => ParentInvocation::current()[0] ?? null,
                'user_id' => resolve(AiRunAttribution::class)->user()?->id ?? Auth::id(),
                'status' => 'failed',
                'error_message' => Str::limit($agentFailedOver->exception->getMessage(), 2000),
            ]);
        }

        $runUsageAccumulator->forget($invocationId);
    }
}
