<?php

declare(strict_types=1);

namespace App\Ai\Middleware;

use App\Ai\Decision\DecisionRunContext;
use App\Ai\Decision\DecisionRunOutOfTime;
use App\Jobs\RunDecisionAgent;
use App\Settings\DecisionAgentSettings;
use Closure;
use Illuminate\Support\Facades\App;
use Laravel\Ai\PendingStep;

/**
 * Bound a whole multi-step DecisionAgent run by the run's deadline: each
 * step's provider timeout is cut to the time left, and a step with less
 * than MIN_STEP_SECONDS left is refused (DecisionRunOutOfTime), so
 * RunDecisionAgent records a Failed decision instead of the worker killing
 * the job. Outside a run with a deadline (a benchmark case, a test) the
 * step passes through.
 *
 * Checked on EVERY step, including step 0: a provider failover re-runs the
 * whole agent loop on the fallback provider starting at step 0 again.
 */
final class StopWhenDecisionRunOutOfTime
{
    /** The shortest step worth starting before the deadline. */
    public const int MIN_STEP_SECONDS = RunDecisionAgent::MIN_AGENT_CALL_TIMEOUT_SECONDS;

    public function handle(PendingStep $pendingStep, Closure $next): mixed
    {
        $secondsLeft = App::bound(DecisionRunContext::class) ? resolve(DecisionRunContext::class)->secondsLeft() : null;

        if ($secondsLeft === null) {
            return $next($pendingStep);
        }

        throw_if($secondsLeft < self::MIN_STEP_SECONDS, DecisionRunOutOfTime::beforeStep($pendingStep->number, resolve(DecisionAgentSettings::class)->runTimeLimit()));

        $timeout = $pendingStep->timeout === null ? $secondsLeft : min($pendingStep->timeout, $secondsLeft);

        return $next(new PendingStep(...[...get_object_vars($pendingStep), 'timeout' => $timeout]));
    }
}
