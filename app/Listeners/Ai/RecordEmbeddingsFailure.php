<?php

declare(strict_types=1);

namespace App\Listeners\Ai;

use App\Ai\AiRunAttribution;
use App\Enums\AiUsageKind;
use App\Services\AiUsage\AiUsageCaller;
use App\Services\AiUsage\UsageRecordWriter;
use App\Support\UpstreamErrorText;
use Illuminate\Support\Facades\Auth;
use Laravel\Ai\Events\EmbeddingsFailed;

/**
 * Bill an embeddings call the provider failed, so the failure shows in AI Usage.
 */
class RecordEmbeddingsFailure
{
    public function handle(EmbeddingsFailed $event): void
    {
        resolve(UsageRecordWriter::class)->record([
            'invocation_id' => $event->invocationId,
            'kind' => AiUsageKind::Embeddings,
            'agent_class' => resolve(AiUsageCaller::class)->current() ?? 'embeddings',
            'provider' => $event->provider->name(),
            'model' => $event->model,
            'prompt_tokens' => 0,
            'completion_tokens' => 0,
            'user_id' => resolve(AiRunAttribution::class)->user()?->id ?? Auth::id(),
            'status' => 'failed',
            'error_message' => UpstreamErrorText::sanitize($event->exception->getMessage(), 2000, UpstreamErrorText::UPSTREAM_EMPTY_FALLBACK),
        ]);
    }
}
