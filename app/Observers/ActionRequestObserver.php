<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\ActionRequest;
use App\Services\Actions\ActionRequestActivityLogger;

class ActionRequestObserver
{
    public function __construct(private readonly ActionRequestActivityLogger $actionRequestActivityLogger) {}

    public function created(ActionRequest $actionRequest): void
    {
        $this->actionRequestActivityLogger->created($actionRequest);
    }

    public function updated(ActionRequest $actionRequest): void
    {
        if (! $actionRequest->wasChanged('status')) {
            return;
        }

        $this->actionRequestActivityLogger->statusChanged($actionRequest);
    }
}
