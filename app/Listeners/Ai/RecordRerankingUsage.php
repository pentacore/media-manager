<?php

declare(strict_types=1);

namespace App\Listeners\Ai;

use App\Ai\AiRunAttribution;
use App\Enums\AiUsageKind;
use App\Services\AiUsage\AiUsageCaller;
use App\Services\AiUsage\UsageRecordWriter;
use Illuminate\Support\Facades\Auth;
use Laravel\Ai\Events\Reranked;

/**
 * Bill every reranking call. Cohere-style providers bill per search unit,
 * priced per thousand via the catalog's search_unit_per_k rate.
 */
class RecordRerankingUsage
{
    public function handle(Reranked $reranked): void
    {
        resolve(UsageRecordWriter::class)->record([
            'invocation_id' => $reranked->invocationId,
            'kind' => AiUsageKind::Reranking,
            'agent_class' => resolve(AiUsageCaller::class)->current() ?? 'reranking',
            'provider' => $reranked->provider->name(),
            'model' => $reranked->model,
            'prompt_tokens' => $reranked->response->usage->inputTokens,
            'completion_tokens' => 0,
            'search_units' => $reranked->response->usage->searchUnits ?? 0,
            'user_id' => resolve(AiRunAttribution::class)->user()?->id ?? Auth::id(),
        ]);
    }
}
