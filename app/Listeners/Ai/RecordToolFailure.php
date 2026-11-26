<?php

declare(strict_types=1);

namespace App\Listeners\Ai;

use App\Ai\Benchmark\ReplayTool;
use App\Ai\StoppedByUser;
use App\Models\AiToolInvocation;
use App\Services\AiUsage\TelemetryGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Ai\Events\ToolFailed;
use Laravel\Ai\Tools\ToolNameResolver;

class RecordToolFailure
{
    /**
     * A sub-agent call a client disconnect ended is recorded as stopped:
     * laravel/ai rethrows the sub-agent's ClientDisconnectedException from
     * the AgentTool call (StoppedByUser::exception()).
     */
    public function handle(ToolFailed $toolFailed): void
    {
        TelemetryGuard::run(function () use ($toolFailed): void {
            $stopped = StoppedByUser::exception($toolFailed->exception);

            DB::transaction(static fn (): AiToolInvocation => AiToolInvocation::create([
                'invocation_id' => $toolFailed->invocationId,
                'tool_invocation_id' => $toolFailed->toolInvocationId,
                'tool_class' => ReplayTool::unwrap($toolFailed->tool)::class,
                'agent_class' => $toolFailed->agent::class,
                'status' => $stopped ? 'stopped' : 'failed',
                'error_code' => $stopped ? null : mb_substr(Str::snake(class_basename($toolFailed->exception)), 0, 64),
                'duration_ms' => (int) round($toolFailed->time),
                'tool_name' => ToolNameResolver::resolve($toolFailed->tool),
            ]));
        });
    }
}
