<?php

declare(strict_types=1);

namespace App\Listeners\Ai;

use App\Services\AiUsage\RunUsageAccumulator;
use Laravel\Ai\Events\StepFailed;

/**
 * Keeps the number of the step that failed (counted from 1; laravel/ai counts
 * from 0) until the run's failed usage row
 * is written (RecordFailedAgentRun, RecordFailedOverAttemptUsage).
 */
class RememberFailedStep
{
    public function handle(StepFailed $stepFailed): void
    {
        resolve(RunUsageAccumulator::class)->markFailedStep($stepFailed->invocationId, $stepFailed->stepNumber + 1);
    }
}
