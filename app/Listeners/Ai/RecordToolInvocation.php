<?php

declare(strict_types=1);

namespace App\Listeners\Ai;

use App\Models\AiToolInvocation;
use Laravel\Ai\Events\ToolInvoked;

class RecordToolInvocation
{
    public function handle(ToolInvoked $toolInvoked): void
    {
        $errorCode = $this->errorCodeFrom($toolInvoked->result);

        AiToolInvocation::create([
            'invocation_id' => $toolInvoked->invocationId,
            'tool_invocation_id' => $toolInvoked->toolInvocationId,
            'tool_class' => $toolInvoked->tool::class,
            'agent_class' => $toolInvoked->agent::class,
            'status' => $errorCode === null ? 'success' : 'failed',
            'error_code' => $errorCode,
            'duration_ms' => (int) round($toolInvoked->time),
        ]);
    }

    /**
     * BaseTool catches every exception and returns an `{"error": …}` envelope,
     * so SDK ToolFailed rarely fires for our tools — read failure off the result.
     */
    private function errorCodeFrom(mixed $result): ?string
    {
        $decoded = is_string($result) ? json_decode($result, true) : $result;

        return is_array($decoded) && is_string($decoded['error'] ?? null)
            ? mb_substr($decoded['error'], 0, 64)
            : null;
    }
}
