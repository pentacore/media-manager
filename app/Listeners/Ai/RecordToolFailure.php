<?php

declare(strict_types=1);

namespace App\Listeners\Ai;

use App\Models\AiToolInvocation;
use Illuminate\Support\Str;
use Laravel\Ai\Events\ToolFailed;

class RecordToolFailure
{
    public function handle(ToolFailed $toolFailed): void
    {
        AiToolInvocation::create([
            'invocation_id' => $toolFailed->invocationId,
            'tool_invocation_id' => $toolFailed->toolInvocationId,
            'tool_class' => $toolFailed->tool::class,
            'agent_class' => $toolFailed->agent::class,
            'status' => 'failed',
            'error_code' => mb_substr(Str::snake(class_basename($toolFailed->exception)), 0, 64),
            'duration_ms' => (int) round($toolFailed->time),
        ]);
    }
}
