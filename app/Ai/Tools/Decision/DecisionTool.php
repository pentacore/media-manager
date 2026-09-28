<?php

declare(strict_types=1);

namespace App\Ai\Tools\Decision;

use App\Ai\Decision\DecisionRunContext;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Base for the DecisionAgent's tools.
 *
 * Deliberately NOT a BaseTool: a background decision run has no chat mode and
 * no authenticated user, so BaseTool's advisory-mode gate and its
 * auth()-bound ActionRequest queueing must not apply. Mutating tools dispatch
 * through ActionOrchestrator::dispatchFromAgent() themselves, which keeps
 * subject binding, forced approval and connection pinning in their hands.
 *
 * handle() resolves the run's DecisionRunContext, refuses without one (unless
 * the tool also serves chat), applies the tool's refusal hook and the per-run
 * action cap, then runs execute(). Argument-shape checks use
 * $request->validate(); a failure comes back as an `invalid_arguments`
 * rejection in the tool's own result shape.
 */
abstract class DecisionTool implements Tool
{
    /** The result key carrying the tool's success flag. */
    protected const string OUTCOME_KEY = 'queued';

    final public function handle(Request $request): Stringable|string
    {
        $decisionRunContext = $this->runContext();

        if (! $decisionRunContext instanceof DecisionRunContext && $this->requiresRunContext()) {
            return $this->encode($this->noActiveRunRejection());
        }

        if ($decisionRunContext instanceof DecisionRunContext) {
            $refusal = $this->refusal();

            if ($refusal !== null) {
                return $this->encode($refusal);
            }

            if ($this->countsTowardActionCap() && $decisionRunContext->capReached()) {
                return $this->encode($this->capReachedRejection());
            }
        }

        try {
            return $this->encode($this->execute($request));
        } catch (ValidationException $validationException) {
            return $this->encode([
                static::OUTCOME_KEY => false,
                'reason' => 'invalid_arguments',
                'errors' => $validationException->errors(),
                'message' => 'Some arguments were invalid. Fix them and call the tool again.',
            ]);
        }
    }

    /**
     * The tool body. It runs only after handle()'s context, refusal and cap
     * checks passed; a tool that requires a context reads it through
     * boundRunContext().
     *
     * @return array<string, mixed>
     */
    abstract protected function execute(Request $request): array;

    /**
     * Whether the tool refuses to run outside a decision run.
     */
    protected function requiresRunContext(): bool
    {
        return true;
    }

    /**
     * Whether the tool queues actions and so stops once the run's cap is reached.
     */
    protected function countsTowardActionCap(): bool
    {
        return true;
    }

    /**
     * A refusal checked before the cap (for example a disabled capability),
     * or null to proceed.
     *
     * @return array<string, mixed>|null
     */
    protected function refusal(): ?array
    {
        return null;
    }

    /**
     * @return array<string, mixed>
     */
    protected function noActiveRunRejection(): array
    {
        return [static::OUTCOME_KEY => false, 'reason' => 'no_active_run'];
    }

    /**
     * @return array<string, mixed>
     */
    protected function capReachedRejection(): array
    {
        return [static::OUTCOME_KEY => false, 'reason' => 'max_actions_reached'];
    }

    protected function runContext(): ?DecisionRunContext
    {
        return app()->bound(DecisionRunContext::class) ? resolve(DecisionRunContext::class) : null;
    }

    /**
     * The run context of a tool that requires one; handle() has already
     * refused the call when none is bound.
     */
    protected function boundRunContext(): DecisionRunContext
    {
        return resolve(DecisionRunContext::class);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function encode(array $payload): string
    {
        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);

        return $encoded === false
            ? sprintf('{"%s":false,"reason":"encoding_failed"}', static::OUTCOME_KEY)
            : $encoded;
    }
}
