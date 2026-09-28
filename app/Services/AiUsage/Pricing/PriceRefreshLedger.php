<?php

declare(strict_types=1);

namespace App\Services\AiUsage\Pricing;

use App\Services\AiUsage\Pricing\Data\PricingRejection;

/**
 * Everything one price refresh run accumulates: per-provider audit counters,
 * the verifier's fallback targets, unverified targets, the first isolated
 * write failure, citations and source statuses. Created per run by
 * AiPriceRefreshCoordinator and handed to each phase, so the services stay
 * stateless.
 */
final class PriceRefreshLedger
{
    /**
     * Per-provider terminal states recorded in the compact audit payload.
     */
    public const string PROVIDER_OK = 'ok';

    public const string PROVIDER_FALLBACK = 'fallback';

    public const string PROVIDER_MISSING = 'missing';

    public const string PROVIDER_MALFORMED = 'malformed';

    public const string PROVIDER_INCOMPLETE = 'incomplete';

    public const string PROVIDER_WRITE_FAILED = 'write_failed';

    public const string PROVIDER_FEED_UNAVAILABLE = 'feed_unavailable';

    public const string PROVIDER_FALLBACK_FAILED = 'fallback_failed';

    public const string PROVIDER_FALLBACK_SKIPPED = 'fallback_skipped';

    /**
     * Upper bound on the number of `provider:model` pairs enumerated in the
     * audit's `unverified_targets`; the overflow is summarized as `+N more`.
     */
    public const int UNVERIFIED_TARGETS_AUDIT_CAP = 25;

    /**
     * Provider => outcome counters and rejection codes accumulated per run.
     *
     * @var array<string, array{status: string, created: int, updated: int, unchanged: int, locked: int, rejected: int, anomalous: int, tiered: int, discrepancies: int, create_disabled: int, consensus: int, conflicts: int, rejections: array<string, int>}>
     */
    public array $providerStates = [];

    /**
     * Provider => exact model targets ([] = every GA model) queued for the
     * verifier agent. Contains both provider-level fallback and model-level
     * anomaly verification targets.
     *
     * @var array<string, list<string>>
     */
    public array $fallbackTargets = [];

    /**
     * Providers whose resolution depends entirely on the verifier (the feed
     * produced nothing usable for them). Providers that resolved via the feed
     * but ride the agent run only for anomaly verification are excluded: their
     * stored values are already safe, so an agent failure does not fail them.
     *
     * @var list<string>
     */
    public array $providerLevelFallback = [];

    /**
     * Exact `provider:model` verification targets the agent phase left
     * unresolved: pairs with no Created/Updated/Unchanged ledger outcome
     * (RejectedAnomalous, Locked, Rejected, or no write at all). Audited on the
     * run row and degrades an otherwise fully-succeeded run to partial; the
     * owning feed-resolved provider keeps its ok status.
     *
     * @var list<string>
     */
    public array $unverifiedTargets = [];

    /**
     * The first isolated per-provider write failure message of the run, folded
     * into the report when no more specific error surfaced.
     */
    public ?string $writeFailureMessage = null;

    /**
     * De-duplicated URL citations the verifier's final response carried, kept
     * on the run row as an audit trail of the pages the model relied on.
     *
     * @var list<array{url: string, title: string|null}>
     */
    public array $sourceCitations = [];

    /**
     * Pricing source key => status for this run, as reported by the catalog.
     *
     * @var array<string, string>
     */
    public array $sourceStatuses = [];

    public function __construct(
        /**
         * Whether this run is a first-party verification pass (see
         * AiPriceRefreshCoordinator::MODE_VERIFY).
         */
        public readonly bool $verifyMode,
    ) {}

    /**
     * Fetch (or initialize) the mutable per-provider counter state.
     *
     * @return array{status: string, created: int, updated: int, unchanged: int, locked: int, rejected: int, anomalous: int, tiered: int, discrepancies: int, create_disabled: int, consensus: int, conflicts: int, rejections: array<string, int>}
     */
    public function providerState(string $provider, string $initialStatus): array
    {
        return $this->providerStates[$provider] ??= [
            'status' => $initialStatus,
            'created' => 0,
            'updated' => 0,
            'unchanged' => 0,
            'locked' => 0,
            'rejected' => 0,
            'anomalous' => 0,
            'tiered' => 0,
            'discrepancies' => 0,
            'create_disabled' => 0,
            'consensus' => 0,
            'conflicts' => 0,
            'rejections' => [],
        ];
    }

    /**
     * Fold adapter rejection codes into the provider's compact audit entry.
     *
     * @param  list<PricingRejection>  $rejections
     */
    public function recordRejectionCodes(string $provider, array $rejections): void
    {
        $state = $this->providerState($provider, self::PROVIDER_OK);

        foreach ($rejections as $rejection) {
            $state['rejections'][$rejection->code] = ($state['rejections'][$rejection->code] ?? 0) + 1;
        }

        $this->providerStates[$provider] = $state;
    }

    /**
     * Queue a provider for verifier fallback unconditionally. Used directly by
     * the isolated write-failure path, where a failed write must never be
     * settled as "nothing to refresh".
     */
    public function enqueueFallback(string $provider, RefreshScope $refreshScope): void
    {
        $this->fallbackTargets[$provider] = $refreshScope->modelsFor($provider) ?? [];
        $this->providerLevelFallback[] = $provider;

        // Pending until the verifier resolves it: never leave a previously
        // initialized state (for example recorded rejection codes) reading as
        // resolved while the provider's outcome depends on the agent.
        $this->providerState($provider, self::PROVIDER_FALLBACK_SKIPPED);
        $this->providerStates[$provider]['status'] = self::PROVIDER_FALLBACK_SKIPPED;
    }

    /**
     * Settle a provider that has nothing to refresh as succeeded without
     * spending an agent run on it, keeping any counters recorded so far.
     */
    public function resolveWithNothingToRefresh(string $provider): void
    {
        $this->providerState($provider, self::PROVIDER_OK);
        $this->providerStates[$provider]['status'] = self::PROVIDER_OK;
    }

    /**
     * Providers still unresolved after an unexpected orchestration failure.
     *
     * @param  list<string>  $requested
     */
    public function failRemainingProviders(array $requested): void
    {
        foreach ($requested as $provider) {
            $status = $this->providerStates[$provider]['status'] ?? null;

            if ($status === null || $status === self::PROVIDER_FALLBACK_SKIPPED) {
                $this->providerState($provider, self::PROVIDER_FALLBACK_FAILED);
                $this->providerStates[$provider]['status'] = self::PROVIDER_FALLBACK_FAILED;
            }
        }
    }

    /**
     * The exact write scope handed to the verifier, built per provider so a
     * whole-provider fallback and an exact-model anomaly target can coexist in
     * one run without widening each other. A provider queued for the whole feed
     * (empty target list) becomes a provider-level wildcard; a provider queued
     * only for specific anomalous models stays bound to exactly those models,
     * even when another provider in the same run needs the whole feed.
     */
    public function agentScope(): RefreshScope
    {
        $targets = [];

        foreach ($this->fallbackTargets as $provider => $models) {
            $targets[$provider] = $models === [] ? null : $models;
        }

        return RefreshScope::forTargets($targets);
    }

    /**
     * The unverified `provider:model` targets for the audit row, de-duplicated
     * and capped at {@see UNVERIFIED_TARGETS_AUDIT_CAP} entries with a trailing
     * `+N more` marker so a wide provider-wide coverage gap cannot bloat the row.
     *
     * @return list<string>
     */
    public function cappedUnverifiedTargets(): array
    {
        $targets = array_values(array_unique($this->unverifiedTargets));

        if (count($targets) <= self::UNVERIFIED_TARGETS_AUDIT_CAP) {
            return $targets;
        }

        $capped = array_slice($targets, 0, self::UNVERIFIED_TARGETS_AUDIT_CAP);
        $capped[] = sprintf('+%d more', count($targets) - self::UNVERIFIED_TARGETS_AUDIT_CAP);

        return $capped;
    }

    /**
     * Compact `provider` / `provider:model` strings for the audit row.
     *
     * @return list<string>
     */
    public function flattenFallbackTargets(): array
    {
        $flat = [];

        foreach ($this->fallbackTargets as $provider => $models) {
            if ($models === []) {
                $flat[] = $provider;

                continue;
            }

            foreach ($models as $model) {
                $flat[] = $provider.':'.$model;
            }
        }

        return $flat;
    }
}
