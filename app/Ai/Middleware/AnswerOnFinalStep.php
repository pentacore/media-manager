<?php

declare(strict_types=1);

namespace App\Ai\Middleware;

use Closure;
use Laravel\Ai\PendingStep;
use Laravel\Ai\ToolChoice;

/**
 * On the last step MaxSteps allows, forbid tools so the run ends with an
 * answer instead of stopping mid tool loop with no reply.
 */
final class AnswerOnFinalStep
{
    public function handle(PendingStep $pendingStep, Closure $next): mixed
    {
        return $next($pendingStep->isFinalStep ? $pendingStep->withToolChoice(ToolChoice::none) : $pendingStep);
    }
}
