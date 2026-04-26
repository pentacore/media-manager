<?php

declare(strict_types=1);

namespace App\Listeners\Ai;

use App\Models\AiToolInvocation;
use Laravel\Ai\Events\ToolInvoked;

class RecordToolInvocation
{
    public function handle(ToolInvoked $toolInvoked): void
    {
        AiToolInvocation::create([
            'invocation_id' => $toolInvoked->invocationId,
            'tool_invocation_id' => $toolInvoked->toolInvocationId,
            'tool_class' => $toolInvoked->tool::class,
            'agent_class' => $toolInvoked->agent::class,
            'status' => 'success',
        ]);
    }
}
