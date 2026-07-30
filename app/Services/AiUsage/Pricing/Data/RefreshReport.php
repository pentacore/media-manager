<?php

declare(strict_types=1);

namespace App\Services\AiUsage\Pricing\Data;

use App\Services\AiUsage\Pricing\AiPriceRefreshCoordinator;

/**
 * The immutable outcome of one {@see AiPriceRefreshCoordinator} run, shaped for
 * the queued job's broadcast payload and the CLI's console output.
 *
 * Carries only compact identifiers and counters — never raw source payloads —
 * so it is safe to broadcast and to log verbatim.
 */
final readonly class RefreshReport
{
    /**
     * Every requested provider resolved (through the feed or the verifier).
     */
    public const string RESULT_SUCCEEDED = 'succeeded';

    /**
     * At least one requested provider resolved and at least one did not.
     */
    public const string RESULT_PARTIAL = 'partial';

    /**
     * No requested provider resolved, or the run could not safely proceed.
     */
    public const string RESULT_FAILED = 'failed';

    /**
     * @param  int|null  $runId  The persisted audit row id, or null when the run row could not be created.
     * @param  string  $finalResult  One of the RESULT_* constants.
     * @param  string|null  $modelsDevStatus  `ok`, `skipped`, `disabled`, or a transport failure category.
     * @param  list<string>  $fallbackProviders  Canonical providers handed to the verifier agent.
     * @param  string  $mode  One of the coordinator MODE_* constants (apply, dry-run, verify).
     */
    public function __construct(
        public ?int $runId,
        public string $finalResult,
        public ?string $modelsDevStatus,
        public int $providersRequested,
        public int $providersSucceeded,
        public int $providersFailed,
        public int $modelsCreated,
        public int $modelsUpdated,
        public int $modelsUnchanged,
        public int $modelsLocked,
        public int $modelsRejected,
        public int $modelsTiered,
        public array $fallbackProviders = [],
        public ?string $errorMessage = null,
        public string $mode = AiPriceRefreshCoordinator::MODE_APPLY,
    ) {}

    /**
     * Flat, snake-cased payload for the price refresh broadcast event.
     *
     * @return array<string, mixed>
     */
    public function toBroadcastArray(): array
    {
        return [
            'run_id' => $this->runId,
            'mode' => $this->mode,
            'final_result' => $this->finalResult,
            'models_dev_status' => $this->modelsDevStatus,
            'providers_requested' => $this->providersRequested,
            'providers_succeeded' => $this->providersSucceeded,
            'providers_failed' => $this->providersFailed,
            'models_created' => $this->modelsCreated,
            'models_updated' => $this->modelsUpdated,
            'models_unchanged' => $this->modelsUnchanged,
            'models_locked' => $this->modelsLocked,
            'models_rejected' => $this->modelsRejected,
            'models_tiered' => $this->modelsTiered,
            'fallback_providers' => $this->fallbackProviders,
            'error_message' => $this->errorMessage,
        ];
    }

    /**
     * Human-readable summary lines for the CLI command.
     *
     * @return list<string>
     */
    public function toConsoleLines(): array
    {
        $lines = [
            sprintf(
                'Price refresh %s%s.',
                $this->finalResult,
                $this->runId !== null ? sprintf(' (run #%d)', $this->runId) : '',
            ),
            sprintf('Models.dev source: %s.', $this->modelsDevStatus ?? 'not attempted'),
            sprintf(
                'Providers: %d requested, %d succeeded, %d failed.',
                $this->providersRequested,
                $this->providersSucceeded,
                $this->providersFailed,
            ),
            sprintf(
                'Models: %d created, %d updated, %d unchanged, %d locked, %d rejected, %d tiered.',
                $this->modelsCreated,
                $this->modelsUpdated,
                $this->modelsUnchanged,
                $this->modelsLocked,
                $this->modelsRejected,
                $this->modelsTiered,
            ),
        ];

        if ($this->fallbackProviders !== []) {
            $lines[] = sprintf('Verifier fallback: %s.', implode(', ', $this->fallbackProviders));
        }

        if ($this->errorMessage !== null) {
            $lines[] = sprintf('Error: %s', $this->errorMessage);
        }

        return $lines;
    }
}
