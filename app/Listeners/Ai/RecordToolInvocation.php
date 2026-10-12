<?php

declare(strict_types=1);

namespace App\Listeners\Ai;

use App\Ai\Benchmark\ReplayTool;
use App\Models\AiToolInvocation;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Events\ToolInvoked;
use Laravel\Ai\Tools\ToolNameResolver;
use Throwable;

class RecordToolInvocation
{
    /**
     * Telemetry must never break the agent run that fired it (listener
     * exceptions propagate into the SDK loop): when the row with the call's
     * I/O can't be written, the row is written without it, and a failure of
     * that is only reported. Each insert runs in its own transaction so a
     * rejected statement rolls back to a savepoint inside a caller's
     * transaction instead of aborting it.
     */
    public function handle(ToolInvoked $toolInvoked): void
    {
        $errorCode = $this->errorCodeFrom($toolInvoked->result);

        $attributes = [
            'invocation_id' => $toolInvoked->invocationId,
            'tool_invocation_id' => $toolInvoked->toolInvocationId,
            'tool_class' => ReplayTool::unwrap($toolInvoked->tool)::class,
            'agent_class' => $toolInvoked->agent::class,
            'status' => $errorCode === null ? 'success' : 'failed',
            'error_code' => $errorCode,
            'duration_ms' => (int) round($toolInvoked->time),
            'tool_name' => ToolNameResolver::resolve($toolInvoked->tool),
        ];

        try {
            [$arguments, $result, $truncated] = $this->io($toolInvoked);

            DB::transaction(static fn (): AiToolInvocation => AiToolInvocation::query()->create([
                ...$attributes,
                'arguments' => $arguments,
                'result' => $result,
                'io_truncated' => $truncated,
            ]));
        } catch (Throwable $throwable) {
            report($throwable);

            try {
                DB::transaction(static fn (): AiToolInvocation => AiToolInvocation::query()->create($attributes));
            } catch (Throwable $fallbackThrowable) {
                report($fallbackThrowable);
            }
        }
    }

    /**
     * The call's arguments and result for benchmark capture, each capped at
     * AiToolInvocation::MAX_IO_BYTES. Oversized arguments become a marker
     * object (a cut JSON object can't be matched); an oversized result is cut
     * and suffixed. NUL characters are dropped: Postgres rejects them in text
     * and jsonb.
     *
     * @return array{0: array<string, mixed>|null, 1: string|null, 2: bool}
     */
    private function io(ToolInvoked $toolInvoked): array
    {
        try {
            $arguments = self::withoutNul($toolInvoked->arguments);
            $encodedArguments = json_encode($arguments, JSON_INVALID_UTF8_SUBSTITUTE);
            $rawResult = self::withoutNul($toolInvoked->result);
            $result = is_string($rawResult) ? $rawResult : json_encode($rawResult, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
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
     * The value with every NUL character removed from its strings and array
     * keys, recursively.
     */
    private static function withoutNul(mixed $value): mixed
    {
        if (is_string($value)) {
            return str_replace("\0", '', $value);
        }

        if (! is_array($value)) {
            return $value;
        }

        $clean = [];

        foreach ($value as $key => $item) {
            $clean[is_string($key) ? str_replace("\0", '', $key) : $key] = self::withoutNul($item);
        }

        return $clean;
    }

    /**
     * BaseTool catches every exception and returns an `{"error": …}` envelope,
     * so SDK ToolFailed rarely fires for our tools — read failure off the result.
     */
    private function errorCodeFrom(mixed $result): ?string
    {
        $decoded = is_string($result) ? json_decode($result, true) : $result;

        return is_array($decoded) && is_string($decoded['error'] ?? null)
            ? mb_substr(str_replace("\0", '', $decoded['error']), 0, 64)
            : null;
    }
}
