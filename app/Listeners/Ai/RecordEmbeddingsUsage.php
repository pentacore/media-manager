<?php

declare(strict_types=1);

namespace App\Listeners\Ai;

use App\Ai\AiRunAttribution;
use App\Enums\AiUsageKind;
use App\Services\AiUsage\AiUsageCaller;
use App\Services\AiUsage\UsageRecordWriter;
use Illuminate\Support\Facades\Auth;
use Laravel\Ai\Events\EmbeddingsGenerated;

/**
 * Bill every provider embeddings call. Cache hits never reach the provider,
 * so the SDK fires no event and nothing is billed for them.
 */
class RecordEmbeddingsUsage
{
    public function handle(EmbeddingsGenerated $embeddingsGenerated): void
    {
        resolve(UsageRecordWriter::class)->record([
            'invocation_id' => $embeddingsGenerated->invocationId,
            'kind' => AiUsageKind::Embeddings,
            'agent_class' => resolve(AiUsageCaller::class)->current() ?? 'embeddings',
            'provider' => $embeddingsGenerated->provider->name(),
            'model' => $embeddingsGenerated->model,
            'prompt_tokens' => $embeddingsGenerated->response->usage->inputTokens,
            'completion_tokens' => 0,
            'user_id' => resolve(AiRunAttribution::class)->user()?->id ?? Auth::id(),
        ]);
    }
}
