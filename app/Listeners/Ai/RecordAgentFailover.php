<?php

declare(strict_types=1);

namespace App\Listeners\Ai;

use Illuminate\Support\Facades\Log;
use Laravel\Ai\Events\AgentFailedOver;
use Laravel\Ai\Events\ProviderFailedOver;

class RecordAgentFailover
{
    public function handle(AgentFailedOver|ProviderFailedOver $event): void
    {
        Log::warning('AI provider failover', [
            'event' => $event::class,
            'provider' => $event->provider->name(),
            'model' => $event->model,
            'agent' => $event instanceof AgentFailedOver ? $event->agent::class : null,
            'exception' => $event->exception::class,
            'message' => $event->exception->getMessage(),
        ]);
    }
}
