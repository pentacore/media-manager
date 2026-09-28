<?php

declare(strict_types=1);

namespace App\Services\AiUsage\Pricing;

use App\Models\AiPriceRefreshRun;
use App\Models\User;
use App\Services\AiUsage\Pricing\Data\RefreshReport;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Throwable;

/**
 * Shared orchestration for every automatic price refresh entry point (queued
 * job and CLI).
 *
 * The coordinator fetches every enabled pricing source through {@see
 * PricingCatalog} (OpenRouter, xAI, models.dev reconciled with LiteLLM),
 * persists candidates through the single {@see AiModelPriceWriter} inside one
 * database transaction per provider, and — for the hybrid source — hands
 * unresolved providers to the scope-bound {@see PriceFetcherAgent} verifier in
 * a single bounded agent run. Every run is audited on {@see AiPriceRefreshRun}
 * with compact counters only; raw source payloads are never persisted.
 *
 * Fallback policy: provider-level failures escalate to the verifier — a
 * global feed failure (transport, invalid JSON/shape), a requested provider
 * missing from the feed, a malformed provider entry, or a structurally valid
 * provider that yielded zero eligible candidates. A candidate withheld
 * solely by the anomaly guard ({@see WriteOutcome::RejectedAnomalous}) is not
 * written and joins the same verifier run as an exact provider/model target,
 * where a receipt-backed first-party verified value may bypass the guard; a
 * failed or absent verification preserves the stored value. A model whose
 * feeds disagreed ({@see ProviderPricingResult::$conflicts}) is not written
 * and joins the verifier run as an exact provider/model target, like an
 * anomaly. Other model-level rejections (deprecated, non-text output,
 * malformed model, missing or invalid costs) are counted and skipped without
 * waking the agent. On a global failure with an unbounded scope, fallback
 * covers the core six providers; Groq, Cohere, and OpenRouter join only when
 * the caller explicitly scoped them.
 *
 * Target resolution is ledger-driven, counts ONLY verification-grade
 * (receipt-backed, primary-rates-supplied) outcomes, and works at two
 * granularities: a provider-level WILDCARD target resolves only when EVERY
 * currently-stored row of that provider is covered by a verification-grade
 * outcome (a provider with zero stored rows resolves on one such write), while
 * an exact provider:model target (anomaly ride-alongs and model-pinned verify
 * targets) resolves ONLY through a verification-grade write of that specific
 * pair. Any unresolved target is audited on the run row as
 * `unverified_targets` (capped, with a `+N more` marker) and caps the run at
 * partial, even though a feed-resolved provider keeps its ok status.
 *
 * This class must never depend on Auth state: attribution comes solely from
 * the `$triggeredBy` argument so queued and scheduled runs behave identically.
 */
final readonly class AiPriceRefreshCoordinator
{
    public const string MODE_APPLY = 'apply';

    public const string MODE_DRY_RUN = 'dry-run';

    /**
     * Behaves exactly like {@see MODE_APPLY} for writes (the first-party
     * verifier persists for real), but is audited distinctly so verification
     * runs can be told apart from ordinary refreshes.
     *
     * Verification grade depends on the fetch path: with the
     * `price_fetcher_provider_webfetch` flag ON the agent uses the SDK's
     * provider-native WebFetch, which produces no local receipts — its writes
     * land as a best-effort refresh but are NEVER verification-grade: no row is
     * stamped `pricing_verified_at`, the anomaly guard cannot be bypassed, and
     * no fallback/verification target resolves, so under that flag agent-phase
     * targets finalize unresolved (partial) with `unverified_targets`
     * populated. Only the default custom-fetch (receipt-gated) path yields
     * verification-grade writes that stamp rows and resolve targets.
     */
    public const string MODE_VERIFY = 'verify';

    public const string SOURCE_HYBRID = 'hybrid';

    public const string SOURCE_MODELS_DEV = 'models-dev';

    public const string SOURCE_AGENT = 'agent';

    /**
     * Models.dev feed status stamped on the run.
     */
    private const string FEED_SKIPPED = 'skipped';

    public function __construct(
        private PricingCatalog $pricingCatalog,
        private PricingFeedPhase $pricingFeedPhase,
        private PriceVerifierPhase $priceVerifierPhase,
        private PriceRefreshFallbackQueue $priceRefreshFallbackQueue,
    ) {}

    /**
     * Execute one refresh run and return its report. Never throws for source
     * or write failures — those are folded into the report and the audit row.
     */
    public function run(
        string $mode,
        string $source,
        RefreshScope $scope,
        ?User $triggeredBy,
        string $trigger,
        bool $dryRun = false,
    ): RefreshReport {
        if (! in_array($mode, [self::MODE_APPLY, self::MODE_DRY_RUN, self::MODE_VERIFY], true)) {
            throw new InvalidArgumentException(sprintf('Unknown refresh mode [%s].', $mode));
        }

        if (! in_array($source, [self::SOURCE_HYBRID, self::SOURCE_MODELS_DEV, self::SOURCE_AGENT], true)) {
            throw new InvalidArgumentException(sprintf('Unknown refresh source [%s].', $source));
        }

        // Dry runs suppress writes. A plain dry run also skips the agent (its
        // writes would persist), while `--verify --dry-run` (spec §22) still
        // runs the agent with dry-run persistence threaded end to end: real
        // fetches and comparison, zero writes, zero verified stamps.
        $verify = $mode === self::MODE_VERIFY;
        $priceRefreshLedger = new PriceRefreshLedger(verifyMode: $verify);
        $isDryRun = $dryRun || $mode === self::MODE_DRY_RUN;
        $requested = $this->requestedProviders($scope);

        try {
            $run = AiPriceRefreshRun::query()->create([
                'mode' => $mode,
                'trigger' => $trigger,
                'triggered_by_user_id' => $triggeredBy?->id,
                'status' => 'running',
                'providers_requested' => count($requested),
                'started_at' => CarbonImmutable::now(),
            ]);
        } catch (Throwable $throwable) {
            return $this->failedWithoutRun($mode, count($requested), $throwable->getMessage());
        }

        $modelsDevStatus = null;
        $errorMessage = null;

        try {
            if ($requested === []) {
                $errorMessage = 'No supported providers were in scope for this run.';
            } elseif ($source === self::SOURCE_AGENT) {
                $modelsDevStatus = self::FEED_SKIPPED;
                $this->priceRefreshFallbackQueue->queueAll($priceRefreshLedger, $requested, $scope, PriceRefreshLedger::PROVIDER_FEED_UNAVAILABLE);
            } elseif ($source === self::SOURCE_HYBRID && ! $this->pricingCatalog->anySourceEnabled()) {
                $modelsDevStatus = PricingFeedPhase::FEED_DISABLED;
                $this->priceRefreshFallbackQueue->queueAll($priceRefreshLedger, $requested, $scope, PriceRefreshLedger::PROVIDER_FEED_UNAVAILABLE);
            } else {
                $modelsDevStatus = $this->pricingFeedPhase->run($priceRefreshLedger, $source, $scope, $requested, $isDryRun, $errorMessage);
            }

            // A verification pass re-reads first-party pages for EVERY scoped
            // provider, not just feed failures and anomalies. Feed-resolved
            // providers are promoted to full provider-level verification targets
            // so the agent must confirm each one; the models-dev source never
            // invokes the agent, so it is left untouched.
            if ($verify && $source !== self::SOURCE_MODELS_DEV) {
                $this->priceRefreshFallbackQueue->queueAllScopedForVerification($priceRefreshLedger, $requested, $scope);
            }

            // The explicit models-dev source never invokes the verifier: any
            // anomaly targets stay recorded on the run for a later scoped
            // verification, exactly like a dry run.
            if ($priceRefreshLedger->fallbackTargets !== [] && $source !== self::SOURCE_MODELS_DEV) {
                $errorMessage = $this->priceVerifierPhase->run($priceRefreshLedger, $triggeredBy, $isDryRun) ?? $errorMessage;
            }
        } catch (Throwable $throwable) {
            $errorMessage = $throwable->getMessage();
            $priceRefreshLedger->failRemainingProviders($requested);
        }

        $errorMessage ??= $priceRefreshLedger->writeFailureMessage;

        return $this->finalize($priceRefreshLedger, $mode, $run, $requested, $modelsDevStatus, $errorMessage);
    }

    /**
     * Canonical providers this run may touch, in configured catalog order.
     *
     * @return list<string>
     */
    private function requestedProviders(RefreshScope $refreshScope): array
    {
        /** @var array<string, string> $map */
        $map = config('mediamanager.ai.pricing.providers', []);

        $providers = [];

        foreach ($map as $canonical) {
            if (! in_array($canonical, $providers, true) && $refreshScope->allowsProvider($canonical)) {
                $providers[] = $canonical;
            }
        }

        return $providers;
    }

    /**
     * Persist the final audit state and build the report.
     *
     * @param  list<string>  $requested
     */
    private function finalize(
        PriceRefreshLedger $priceRefreshLedger,
        string $mode,
        AiPriceRefreshRun $aiPriceRefreshRun,
        array $requested,
        ?string $modelsDevStatus,
        ?string $errorMessage,
    ): RefreshReport {
        $succeeded = 0;
        $totals = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'locked' => 0, 'rejected' => 0, 'tiered' => 0, 'create_disabled' => 0];
        $providerResults = [];

        foreach ($priceRefreshLedger->providerStates as $provider => $state) {
            if (in_array($state['status'], [PriceRefreshLedger::PROVIDER_OK, PriceRefreshLedger::PROVIDER_FALLBACK], true)) {
                $succeeded++;
            }

            foreach (array_keys($totals) as $key) {
                $totals[$key] += $state[$key];
            }

            $providerResults[$provider] = array_filter(
                ['status' => $state['status'], ...array_diff_key($state, ['status' => true, 'rejections' => true])],
                fn (int|string $value): bool => $value !== 0,
            ) + ($state['rejections'] !== [] ? ['rejections' => $state['rejections']] : []);
        }

        $requestedCount = count($requested);
        $failed = max(0, $requestedCount - $succeeded);

        $finalResult = match (true) {
            $requestedCount === 0, $succeeded === 0 => RefreshReport::RESULT_FAILED,
            $failed === 0 => RefreshReport::RESULT_SUCCEEDED,
            default => RefreshReport::RESULT_PARTIAL,
        };

        // An unverified exact-model target caps the run at partial even when
        // every provider resolved: its feed-resolved provider keeps ok status,
        // but the run must not read fully verified while a targeted
        // provider:model pair was never confirmed by a verification-grade write.
        $auditTargets = $priceRefreshLedger->cappedUnverifiedTargets();

        if ($finalResult === RefreshReport::RESULT_SUCCEEDED && $auditTargets !== []) {
            $finalResult = RefreshReport::RESULT_PARTIAL;
        }

        if ($auditTargets !== []) {
            $errorMessage ??= sprintf(
                'Unverified verification targets: %s.',
                implode(', ', $auditTargets),
            );
        }

        $aiPriceRefreshRun->fill([
            'status' => $finalResult,
            'models_dev_status' => $modelsDevStatus,
            'providers_succeeded' => $succeeded,
            'providers_failed' => $failed,
            'models_created' => $totals['created'],
            'models_updated' => $totals['updated'],
            'models_unchanged' => $totals['unchanged'],
            'models_locked' => $totals['locked'],
            'models_rejected' => $totals['rejected'],
            'models_tiered' => $totals['tiered'],
            'fallback_targets' => $priceRefreshLedger->flattenFallbackTargets(),
            'unverified_targets' => $auditTargets === [] ? null : $auditTargets,
            'provider_results' => $providerResults,
            'source_citations' => $priceRefreshLedger->sourceCitations === [] ? null : $priceRefreshLedger->sourceCitations,
            'source_statuses' => $priceRefreshLedger->sourceStatuses === [] ? null : $priceRefreshLedger->sourceStatuses,
            'error_message' => $errorMessage,
            'completed_at' => CarbonImmutable::now(),
        ]);

        try {
            $aiPriceRefreshRun->save();
        } catch (Throwable) {
            // Broad database unavailability: the audit row cannot be finalized,
            // but the caller still gets the report of what happened.
        }

        return new RefreshReport(
            runId: $aiPriceRefreshRun->id,
            finalResult: $finalResult,
            modelsDevStatus: $modelsDevStatus,
            providersRequested: $requestedCount,
            providersSucceeded: $succeeded,
            providersFailed: $failed,
            modelsCreated: $totals['created'],
            modelsUpdated: $totals['updated'],
            modelsUnchanged: $totals['unchanged'],
            modelsLocked: $totals['locked'],
            modelsRejected: $totals['rejected'],
            modelsTiered: $totals['tiered'],
            fallbackProviders: array_keys($priceRefreshLedger->fallbackTargets),
            errorMessage: $errorMessage,
            mode: $mode,
            modelsCreateDisabled: $totals['create_disabled'],
            sourceStatuses: $priceRefreshLedger->sourceStatuses,
        );
    }

    /**
     * Report for a run whose audit row could not even be created — the
     * coordinator cannot safely access the database.
     */
    private function failedWithoutRun(string $mode, int $requestedCount, string $errorMessage): RefreshReport
    {
        return new RefreshReport(
            runId: null,
            finalResult: RefreshReport::RESULT_FAILED,
            modelsDevStatus: null,
            providersRequested: $requestedCount,
            providersSucceeded: 0,
            providersFailed: $requestedCount,
            modelsCreated: 0,
            modelsUpdated: 0,
            modelsUnchanged: 0,
            modelsLocked: 0,
            modelsRejected: 0,
            modelsTiered: 0,
            fallbackProviders: [],
            errorMessage: $errorMessage,
            mode: $mode,
        );
    }
}
