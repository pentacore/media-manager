<?php

declare(strict_types=1);

namespace App\Listeners\Ai;

use App\Ai\Benchmark\ReplayTool;
use App\Models\AiToolInvocation;
use Laravel\Ai\Events\ToolInvoked;
use Laravel\Ai\Tools\ToolNameResolver;
use Throwable;

class RecordToolInvocation
{
    public function handle(ToolInvoked $toolInvoked): void
    {
        $errorCode = $this->errorCodeFrom($toolInvoked->result);
        [$arguments, $result, $truncated] = $this->io($toolInvoked);

        AiToolInvocation::create([
            'invocation_id' => $toolInvoked->invocationId,
            'tool_invocation_id' => $toolInvoked->toolInvocationId,
            'tool_class' => ReplayTool::unwrap($toolInvoked->tool)::class,
            'agent_class' => $toolInvoked->agent::class,
            'status' => $errorCode === null ? 'success' : 'failed',
            'error_code' => $errorCode,
            'duration_ms' => (int) round($toolInvoked->time),
            'tool_name' => ToolNameResolver::resolve($toolInvoked->tool),
            'arguments' => $arguments,
            'result' => $result,
            'io_truncated' => $truncated,
        ]);
    }

    /**
     * The call's arguments and result for benchmark capture, each capped at
     * AiToolInvocation::MAX_IO_BYTES. Oversized arguments become a marker
     * object (a cut JSON object can't be matched); an oversized result is cut
     * and suffixed.
     *
     * @return array{0: array<string, mixed>|null, 1: string|null, 2: bool}
     */
    private function io(ToolInvoked $toolInvoked): array
    {
        try {
            $arguments = $toolInvoked->arguments;
            $encodedArguments = json_encode($arguments, JSON_INVALID_UTF8_SUBSTITUTE);
            $result = is_string($toolInvoked->result) ? $toolInvoked->result : json_encode($toolInvoked->result, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
            $truncated = false;

            if ($encodedArguments === false) {
                $arguments = ['_unencodable' => true];
            } elseif (strlen($encodedArguments) > AiToolInvocation::MAX_IO_BYTES) {
                $arguments = ['_truncated' => true];
                $truncated = true;
            } else {
                $arguments = json_decode($encodedArguments, true);
            }

            if (is_string($result)) {
                $result = mb_scrub($result, 'UTF-8');

                if (strlen($result) > AiToolInvocation::MAX_IO_BYTES) {
                    $result = mb_strcut($result, 0, AiToolInvocation::MAX_IO_BYTES, 'UTF-8').'…[truncated]';
                    $truncated = true;
                }
            }

            return [is_array($arguments) ? $arguments : null, is_string($result) ? $result : null, $truncated];
        } catch (Throwable) {
            return [null, null, false];
        }
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
