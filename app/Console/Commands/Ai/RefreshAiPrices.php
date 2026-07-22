<?php

declare(strict_types=1);

namespace App\Console\Commands\Ai;

use App\Jobs\RefreshAiPricesJob;
use App\Services\AiUsage\Pricing\AiPriceRefreshCoordinator;
use App\Services\AiUsage\Pricing\Data\RefreshReport;
use App\Services\AiUsage\Pricing\RefreshScope;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Shared CLI entry point into the price-refresh pipeline. It validates the
 * requested scope and source, claims the same global lock the queued job uses,
 * delegates to {@see AiPriceRefreshCoordinator}, and renders the run report.
 *
 * `--verify` mapping: verification is feed-then-verify (spec §14.4). It runs the
 * requested source's Models.dev feed phase normally and THEN re-reads the
 * canonical first-party pages for every scoped provider, recording per-provider
 * discrepancies against the just-synced feed. It runs in the dedicated `verify`
 * mode so the audit row distinguishes it from an ordinary apply, and always
 * binds an explicit provider scope: the caller's `--provider` list when given,
 * otherwise the core six providers (Groq, Cohere, and OpenRouter are never
 * verified by default). `--verify` no longer forces a source: the default
 * `hybrid` does feed-then-verify, `--source=agent` verifies without a feed, and
 * `--source=models-dev` is rejected as contradictory (it can never invoke the
 * verifier). `--verify --dry-run` is valid (spec §22) and genuinely verifies:
 * the feed dry-runs AND the verifier agent still runs with end-to-end dry-run
 * persistence — real fetches, real parsing, real first-party comparison —
 * writing nothing and stamping nothing. Targets resolve from the actual
 * comparison, so a dry verification reports succeeded/partial honestly.
 *
 * Exit codes: only a fully failed run exits non-zero. A partial run exits zero
 * because work was applied; monitoring should read the rendered final result
 * (or the ai_price_refresh_runs row) rather than the exit code for degradation.
 */
#[Signature('ai:refresh-prices
    {--verify : Verify requested providers against first-party pages}
    {--provider=* : Restrict to MediaManager provider IDs}
    {--dry-run : Parse, validate, and report without changing prices}
    {--source=hybrid : hybrid, models-dev, or agent}
    {--scheduled : Internal flag set by the scheduler for audit provenance}')]
#[Description('Refreshes ai_model_prices from the Models.dev feed and/or the first-party verifier agent.')]
class RefreshAiPrices extends Command
{
    /**
     * Providers verified by default when `--verify` is passed without an
     * explicit `--provider` scope. Mirrors the coordinator's core fallback set.
     *
     * @var list<string>
     */
    private const array CORE_PROVIDERS = [
        'openai',
        'anthropic',
        'gemini',
        'xai',
        'deepseek',
        'mistral',
    ];

    public function handle(): int
    {
        // Scheduled invocations pass --scheduled so the audit row and the lock
        // owner distinguish them from an operator typing the command by hand.
        $trigger = $this->option('scheduled') ? 'schedule' : 'command';

        /** @var list<string> $providers */
        $providers = array_values(array_filter(
            array_map(trim(...), (array) $this->option('provider')),
            fn (string $provider): bool => $provider !== '',
        ));

        $verify = (bool) $this->option('verify');
        $dryRun = (bool) $this->option('dry-run');
        $source = (string) $this->option('source');

        // Validate scope and source before touching the lock so an operator
        // typo fails fast without blocking a concurrent legitimate refresh.
        if (! $this->validateSource($source) || ! $this->validateProviders($providers)) {
            return self::FAILURE;
        }

        // The models-dev source can never invoke the verifier, so pairing it
        // with --verify is contradictory; reject it before claiming the lock.
        if ($verify && $source === AiPriceRefreshCoordinator::SOURCE_MODELS_DEV) {
            $this->error('Cannot combine --verify with --source=models-dev: the models-dev source never invokes the first-party verifier. Use hybrid (feed then verify) or agent (verify only).');

            return self::FAILURE;
        }

        $refreshScope = $this->resolveScope($verify, $providers);
        $mode = match (true) {
            $verify => AiPriceRefreshCoordinator::MODE_VERIFY,
            $dryRun => AiPriceRefreshCoordinator::MODE_DRY_RUN,
            default => AiPriceRefreshCoordinator::MODE_APPLY,
        };

        if (! RefreshAiPricesJob::tryLock($trigger === 'schedule' ? 'schedule' : 'cli')) {
            $this->error('A price refresh is already running. Wait for it to finish.');

            return self::FAILURE;
        }

        try {
            // Attribution is intentionally null for CLI runs: they are not owned
            // by any admin user, exactly like the scheduled invocation.
            $report = resolve(AiPriceRefreshCoordinator::class)->run(
                mode: $mode,
                source: $source,
                scope: $refreshScope,
                triggeredBy: null,
                trigger: $trigger,
                dryRun: $dryRun,
            );

            $this->renderReport($report, $dryRun || $mode === AiPriceRefreshCoordinator::MODE_DRY_RUN);

            return $report->finalResult === RefreshReport::RESULT_FAILED
                ? self::FAILURE
                : self::SUCCESS;
        } finally {
            Cache::forget(RefreshAiPricesJob::LOCK_KEY);
        }
    }

    /**
     * The provider/model allowlist for this run. `--verify` always yields a
     * bounded scope; a bare refresh is unbounded unless providers were named.
     *
     * @param  list<string>  $providers
     */
    private function resolveScope(bool $verify, array $providers): RefreshScope
    {
        if ($verify) {
            return RefreshScope::forProviders($providers === [] ? self::CORE_PROVIDERS : $providers);
        }

        return $providers === [] ? RefreshScope::all() : RefreshScope::forProviders($providers);
    }

    private function validateSource(string $source): bool
    {
        $allowed = [
            AiPriceRefreshCoordinator::SOURCE_HYBRID,
            AiPriceRefreshCoordinator::SOURCE_MODELS_DEV,
            AiPriceRefreshCoordinator::SOURCE_AGENT,
        ];

        if (in_array($source, $allowed, true)) {
            return true;
        }

        $this->error(sprintf('Unknown source [%s]. Use one of: %s.', $source, implode(', ', $allowed)));

        return false;
    }

    /**
     * Every requested provider must resolve to a supported canonical identity;
     * both upstream (`google`) and canonical (`gemini`) spellings are accepted.
     *
     * @param  list<string>  $providers
     */
    private function validateProviders(array $providers): bool
    {
        /** @var array<string, string> $map */
        $map = config('mediamanager.ai.pricing.providers', []);

        foreach ($providers as $provider) {
            if (RefreshScope::canonicalProvider($provider) === null) {
                $this->error(sprintf(
                    'Unknown provider [%s]. Use one of: %s.',
                    $provider,
                    implode(', ', array_unique([...array_keys($map), ...array_values($map)])),
                ));

                return false;
            }
        }

        return true;
    }

    private function renderReport(RefreshReport $report, bool $isDryRun): void
    {
        $this->newLine();

        foreach ($report->toConsoleLines() as $line) {
            $this->line($line);
        }

        if ($isDryRun && $report->fallbackProviders !== []) {
            $this->newLine();
            $this->warn(
                $report->mode === AiPriceRefreshCoordinator::MODE_VERIFY
                    ? 'Verify dry run: the verifier fetched and compared first-party pages for real; only persistence was skipped — no rows were written and no verification was stamped.'
                    : 'Dry run: the verifier agent never runs, so providers that would fall back stay unresolved and the result reads as partial by design.'
            );
        }

        $this->newLine();
    }
}
