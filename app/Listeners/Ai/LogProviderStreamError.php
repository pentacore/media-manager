<?php

declare(strict_types=1);

namespace App\Listeners\Ai;

use App\Support\UpstreamErrorText;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Events\StepFailed;
use Laravel\Ai\Exceptions\StreamErrorException;

/**
 * laravel/ai's stream protocol never reports a StreamErrorException (an error
 * the provider sent inside the stream), so a failed chat turn otherwise leaves
 * nothing in the container logs. OpenAI puts its request id in the message.
 * Other step failures are thrown and reach the exception handler as usual.
 */
class LogProviderStreamError
{
    public function handle(StepFailed $stepFailed): void
    {
        $exception = $stepFailed->exception;

        if (! $exception instanceof StreamErrorException) {
            return;
        }

        Log::warning('AI provider stream error', [
            'provider' => $stepFailed->provider->name(),
            'model' => $stepFailed->model,
            'agent' => $stepFailed->agent::class,
            'invocation_id' => $stepFailed->invocationId,
            'step' => $stepFailed->stepNumber,
            'error_type' => $exception->error?->type,
            'error' => UpstreamErrorText::sanitize($exception->getMessage(), 2000),
            'metadata' => $exception->error?->metadata,
        ]);
    }
}
