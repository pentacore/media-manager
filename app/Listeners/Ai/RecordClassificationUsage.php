<?php

declare(strict_types=1);

namespace App\Listeners\Ai;

use App\Ai\AiRunAttribution;
use App\Enums\AiUsageKind;
use App\Services\AiUsage\AiUsageCaller;
use App\Services\AiUsage\UsageColumns;
use App\Services\AiUsage\UsageRecordWriter;
use Illuminate\Support\Facades\Auth;
use Laravel\Ai\Events\Classified;

/**
 * Bill every classification call that gates an agent run, labelled with
 * the caller the Classifier bound around it.
 */
class RecordClassificationUsage
{
    public function handle(Classified $classified): void
    {
        resolve(UsageRecordWriter::class)->record([
            'invocation_id' => $classified->invocationId,
            'kind' => AiUsageKind::Classification,
            'agent_class' => resolve(AiUsageCaller::class)->current() ?? 'classification',
            'provider' => $classified->provider->name(),
            'model' => $classified->model,
            ...UsageColumns::fromText($classified->response->usage),
            'user_id' => resolve(AiRunAttribution::class)->user()?->id ?? Auth::id(),
        ]);
    }
}
