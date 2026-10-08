<?php

declare(strict_types=1);

namespace App\Observers;

use App\Ai\TaskModelResolver;

/**
 * Keeps the request-scoped resolver memo in step with saved selections.
 */
class AiTaskModelObserver
{
    public function saved(): void
    {
        resolve(TaskModelResolver::class)->flush();
    }

    public function deleted(): void
    {
        resolve(TaskModelResolver::class)->flush();
    }
}
