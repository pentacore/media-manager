<?php

declare(strict_types=1);

namespace App\Listeners\Ai;

use App\Services\AiUsage\RunUsageAccumulator;
use Laravel\Ai\Events\StepCompleted;

class AccumulateStepUsage
{
    public function handle(StepCompleted $stepCompleted): void
    {
        resolve(RunUsageAccumulator::class)->add(
            $stepCompleted->invocationId,
            $stepCompleted->provider->name(),
            $stepCompleted->model,
            $stepCompleted->response->usage,
        );
    }
}
