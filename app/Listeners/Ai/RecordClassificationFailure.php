<?php

declare(strict_types=1);

namespace App\Listeners\Ai;

use App\Ai\AiRunAttribution;
use App\Enums\AiUsageKind;
use App\Services\AiUsage\AiUsageCaller;
use App\Services\AiUsage\UsageRecordWriter;
use App\Services\AiUsage\UsageText;
use App\Support\UpstreamErrorText;
use Illuminate\Support\Facades\Auth;
use Laravel\Ai\Events\ClassificationFailed;

/**
 * Bill a classification call the provider failed, labelled with the caller the Classifier bound around it.
 */
class RecordClassificationFailure
{
    public function handle(ClassificationFailed $event): void
    {
        resolve(UsageRecordWriter::class)->record([
            'invocation_id' => $event->invocationId,
            'kind' => AiUsageKind::Classification,
            'agent_class' => resolve(AiUsageCaller::class)->current() ?? 'classification',
            'provider' => $event->provider->name(),
            'model' => $event->model,
            'prompt_tokens' => 0,
            'completion_tokens' => 0,
            'prompt_text' => UsageText::classificationInput($event->prompt),
            'user_id' => resolve(AiRunAttribution::class)->user()?->id ?? Auth::id(),
            'status' => 'failed',
            'error_message' => UpstreamErrorText::sanitize($event->exception->getMessage(), 2000, UpstreamErrorText::UPSTREAM_EMPTY_FALLBACK),
        ]);
    }
}
