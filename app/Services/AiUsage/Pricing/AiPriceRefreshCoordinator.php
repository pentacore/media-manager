<?php

declare(strict_types=1);

namespace App\Services\AiUsage\Pricing;

use App\Ai\Agents\PriceFetcherAgent;
use App\Enums\PricingSource;
use App\Models\AiModelPrice;
use App\Models\AiPriceRefreshRun;
use App\Models\User;
use App\Services\AiBudget\AiBudgetGuard;
use App\Services\AiUsage\Pricing\Data\PricingRejection;
use App\Services\AiUsage\Pricing\Data\ProviderPricingResult;
use App\Services\AiUsage\Pricing\Data\RefreshReport;
use App\Services\AiUsage\Pricing\Data\WriteOutcome;
use App\Settings\AiSettings;
use Carbon\CarbonImmutable;
use Illuminate\Database\DetectsLostConnections;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

/**
 * Shared orchestration for every automatic price refresh entry point (queued
 * job and CLI).
 *
 * The coordinator fetches the Models.dev feed, adapts it per provider, persists
 * candidates through the single {@see AiModelPriceWriter} inside one database
 * transaction per provider, and — for the hybrid source — hands unresolved
 * providers to the scope-bound {@see PriceFetcherAgent} verifier in a single
 * bounded agent run. Every run is audited on {@see AiPriceRefreshRun} with
 * compact counters only; raw source payloads are never persisted.
 *
 * Fallback policy: provider-level failures escalate to the verifier — a
 * global feed failure (transport, invalid JSON/shape), a requested provider
 * missing from the feed, a malformed provider entry, or a structurally valid
 * provider that yielded zero eligible candidates. A candidate withheld
 * solely by the anomaly guard ({@see WriteOutcome::RejectedAnomalous}) is not
 * written and joins the same verifier run as an exact provider/model target,
 * where a receipt-backed first-party verified value may bypass the guard; a
 * failed or absent verification preserves the stored value. Other model-level
 * rejections (deprecated, non-text output, malformed model, missing or invalid
 * costs) are counted and skipped without waking the agent. On a global failure
 * with an unbounded scope, fallback covers the core six providers; Groq,
 * Cohere, and OpenRouter join only when the caller explicitly scoped them.
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
final class AiPriceRefreshCoordinator
{
    use DetectsLostConnections;

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
    private const string FEED_OK = 'ok';

    private const string FEED_SKIPPED = 'skipped';

    private const string FEED_DISABLED = 'disabled';

    /**
     * Providers the verifier covers by default when a global feed failure
     * leaves an unbounded scope with nothing. Groq, Cohere, and OpenRouter are
     * intentionally absent: they are verified only when explicitly scoped.
     *
     * @var list<string>
     */
    private const array CORE_FALLBACK_PROVIDERS = [
        'openai',
        'anthropic',
        'gemini',
        'xai',
        'deepseek',
        'mistral',
    ];

    /**
     * Per-provider terminal states recorded in the compact audit payload.
     */
    private const string PROVIDER_OK = 'ok';

    private const string PROVIDER_FALLBACK = 'fallback';

    private const string PROVIDER_MISSING = 'missing';

    private const string PROVIDER_MALFORMED = 'malformed';

    private const string PROVIDER_INCOMPLETE = 'incomplete';

    private const string PROVIDER_WRITE_FAILED = 'write_failed';

    private const string PROVIDER_FEED_UNAVAILABLE = 'feed_unavailable';

    private const string PROVIDER_FALLBACK_FAILED = 'fallback_failed';

    private const string PROVIDER_FALLBACK_SKIPPED = 'fallback_skipped';

    /**
     * Upper bound on the number of `provider:model` pairs enumerated in the
     * audit's `unverified_targets`; the overflow is summarized as `+N more`.
     */
    private const int UNVERIFIED_TARGETS_AUDIT_CAP = 25;

    /**
     * Provider => outcome counters and rejection codes accumulated per run.
     *
     * @var array<string, array{status: string, created: int, updated: int, unchanged: int, locked: int, rejected: int, anomalous: int, tiered: int, discrepancies: int, rejections: array<string, int>}>
     */
    private array $providerStates = [];

    /**
     * Provider => exact model targets ([] = every GA model) queued for the
     * verifier agent. Contains both provider-level fallback and model-level
     * anomaly verification targets.
     *
     * @var array<string, list<string>>
     */
    private array $fallbackTargets = [];

    /**
     * Providers whose resolution depends entirely on the verifier (the feed
     * produced nothing usable for them). Providers that resolved via the feed
     * but ride the agent run only for anomaly verification are excluded: their
     * stored values are already safe, so an agent failure does not fail them.
     *
     * @var list<string>
     */
    private array $providerLevelFallback = [];

    /**
     * Exact `provider:model` verification targets the agent phase left
     * unresolved: pairs with no Created/Updated/Unchanged ledger outcome
     * (RejectedAnomalous, Locked, Rejected, or no write at all). Audited on the
     * run row and degrades an otherwise fully-succeeded run to partial; the
     * owning feed-resolved provider keeps its ok status.
     *
     * @var list<string>
     */
    private array $unverifiedTargets = [];

    /**
     * The first isolated per-provider write failure message of the run, folded
     * into the report when no more specific error surfaced.
     */
    private ?string $writeFailureMessage = null;

    /**
     * Whether this run is a first-party verification pass. When true the feed
     * runs normally and then EVERY scoped provider is queued for the agent to
     * re-read its canonical pages, and per-provider `discrepancies` (first-party
     * values that differed from the just-synced feed) are recorded.
     */
    private bool $verifyMode = false;

    public function __construct(
        private readonly ModelsDevPricingClient $modelsDevPricingClient,
        private readonly ModelsDevPricingAdapter $modelsDevPricingAdapter,
        private readonly AiModelPriceWriter $aiModelPriceWriter,
        private readonly AiBudgetGuard $aiBudgetGuard,
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

        $this->providerStates = [];
        $this->fallbackTargets = [];
        $this->providerLevelFallback = [];
        $this->unverifiedTargets = [];
        $this->writeFailureMessage = null;

        // Dry runs suppress writes. A plain dry run also skips the agent (its
        // writes would persist), while `--verify --dry-run` (spec §22) still
        // runs the agent with dry-run persistence threaded end to end: real
        // fetches and comparison, zero writes, zero verified stamps.
        $verify = $mode === self::MODE_VERIFY;
        $this->verifyMode = $verify;
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
                $this->queueAllForFallback($requested, $scope, self::PROVIDER_FEED_UNAVAILABLE, fallbackAll: true);
            } elseif ($source === self::SOURCE_HYBRID && ! resolve(AiSettings::class)->modelsDevPricingEnabled()) {
                $modelsDevStatus = self::FEED_DISABLED;
                $this->queueAllForFallback($requested, $scope, self::PROVIDER_FEED_UNAVAILABLE, fallbackAll: false);
            } else {
                $modelsDevStatus = $this->runFeedPhase($source, $scope, $requested, $isDryRun, $errorMessage);
            }

            // A verification pass re-reads first-party pages for EVERY scoped
            // provider, not just feed failures and anomalies. Feed-resolved
            // providers are promoted to full provider-level verification targets
            // so the agent must confirm each one; the models-dev source never
            // invokes the agent, so it is left untouched.
            if ($verify && $source !== self::SOURCE_MODELS_DEV) {
                $this->queueAllScopedForVerification($requested, $scope);
            }

            // The explicit models-dev source never invokes the verifier: any
            // anomaly targets stay recorded on the run for a later scoped
            // verification, exactly like a dry run.
            if ($this->fallbackTargets !== [] && $source !== self::SOURCE_MODELS_DEV) {
                $errorMessage = $this->runAgentPhase($triggeredBy, $isDryRun) ?? $errorMessage;
            }
        } catch (Throwable $throwable) {
            $errorMessage = $throwable->getMessage();
            $this->failRemainingProviders($requested);
        }

        $errorMessage ??= $this->writeFailureMessage;

        return $this->finalize($mode, $run, $requested, $modelsDevStatus, $errorMessage);
    }

    /**
     * Fetch and persist the Models.dev feed slice of the run. Returns the feed
     * status; on a global source failure, queues fallback (hybrid only) and
     * records the failure message into `$errorMessage`.
     */
    private function runFeedPhase(
        string $source,
        RefreshScope $refreshScope,
        array $requested,
        bool $dryRun,
        ?string &$errorMessage,
    ): string {
        try {
            $decoded = $this->modelsDevPricingClient->fetch();
        } catch (ModelsDevTransportException $modelsDevTransportException) {
            $errorMessage = $modelsDevTransportException->getMessage();

            $this->queueAllForFallback(
                $requested,
                $refreshScope,
                self::PROVIDER_FEED_UNAVAILABLE,
                fallbackAll: false,
                fallbackAllowed: $source === self::SOURCE_HYBRID,
            );

            return $modelsDevTransportException->category;
        }

        $results = $this->modelsDevPricingAdapter->adapt($decoded, $refreshScope);

        foreach ($requested as $provider) {
            $result = $results[$provider] ?? null;

            if ($result === null) {
                $this->resolveProviderFailure($provider, $refreshScope, self::PROVIDER_MISSING, $source === self::SOURCE_HYBRID);

                continue;
            }

            if ($this->isMalformedProvider($result)) {
                $this->recordRejectionCodes($provider, $result->rejections);
                $this->resolveProviderFailure($provider, $refreshScope, self::PROVIDER_MALFORMED, $source === self::SOURCE_HYBRID);

                continue;
            }

            if ($result->candidates === []) {
                // Structurally valid but zero eligible candidates (all
                // deprecated / non-text / out of scope): the feed answered
                // nothing usable, so the provider is incomplete and escalates
                // like a missing provider rather than counting as succeeded.
                $this->recordRejectionCodes($provider, $result->rejections);
                $this->providerStates[$provider]['rejected'] += count($result->rejections);
                $this->resolveProviderFailure($provider, $refreshScope, self::PROVIDER_INCOMPLETE, $source === self::SOURCE_HYBRID);

                continue;
            }

            $this->writeProviderCandidates($provider, $result, $refreshScope, $dryRun, $source === self::SOURCE_HYBRID);
        }

        return self::FEED_OK;
    }

    /**
     * Persist one provider's candidates inside a single transaction and fold
     * every outcome into the provider's counters.
     */
    private function writeProviderCandidates(
        string $provider,
        ProviderPricingResult $providerPricingResult,
        RefreshScope $refreshScope,
        bool $dryRun,
        bool $fallbackAllowed,
    ): void {
        $this->recordRejectionCodes($provider, $providerPricingResult->rejections);

        $stateBeforeWrites = $this->providerStates[$provider];
        $stateBeforeWrites['rejected'] += count($providerPricingResult->rejections);

        $state = $stateBeforeWrites;
        $state['status'] = self::PROVIDER_OK;

        $anomalousModels = [];

        try {
            DB::transaction(function () use ($providerPricingResult, $refreshScope, $dryRun, &$state, &$anomalousModels): void {
                foreach ($providerPricingResult->candidates as $candidate) {
                    $outcome = $this->aiModelPriceWriter->write($candidate, $refreshScope, PricingSource::ModelsDev, dryRun: $dryRun);

                    match ($outcome) {
                        WriteOutcome::Created, WriteOutcome::WouldCreate => $state['created']++,
                        WriteOutcome::Updated, WriteOutcome::WouldUpdate => $state['updated']++,
                        WriteOutcome::Unchanged => $state['unchanged']++,
                        WriteOutcome::Locked => $state['locked']++,
                        WriteOutcome::Rejected => $state['rejected']++,
                        WriteOutcome::RejectedAnomalous => $state['anomalous']++,
                    };

                    if ($outcome === WriteOutcome::RejectedAnomalous) {
                        $anomalousModels[] = $candidate->model;
                    }

                    if ($candidate->tiered && $outcome !== WriteOutcome::Rejected && $outcome !== WriteOutcome::RejectedAnomalous) {
                        $state['tiered']++;
                    }
                }
            });
        } catch (Throwable $throwable) {
            // Broad database unavailability aborts the run entirely: the
            // remaining providers cannot be written and the verifier agent
            // (whose tool writes to the same database) must not be invoked.
            if ($this->causedByLostConnection($throwable) || ($throwable->getPrevious() instanceof Throwable && $this->causedByLostConnection($throwable->getPrevious()))) {
                // Record this provider as failed before aborting so it is not
                // left mid-initialization reading as succeeded when the run
                // finalizes after the whole-run abort.
                $this->providerStates[$provider] = [...$stateBeforeWrites, 'status' => self::PROVIDER_WRITE_FAILED];

                throw $throwable;
            }

            // An isolated per-provider failure rolled this provider's slice
            // back; discard its partial counters. The database is still healthy
            // (this was not a lost connection), so in hybrid the provider is not
            // terminal: escalate it to the first-party verifier exactly like a
            // missing/malformed provider. The ledger then resolves it only if
            // the verifier produces a real write; otherwise it stays unresolved.
            $this->writeFailureMessage ??= $throwable->getMessage();

            if ($fallbackAllowed && $this->isFallbackEligible($provider, $refreshScope)) {
                $this->providerStates[$provider] = $stateBeforeWrites;
                $this->queueFallback($provider, $refreshScope);

                return;
            }

            $this->providerStates[$provider] = [...$stateBeforeWrites, 'status' => self::PROVIDER_WRITE_FAILED];

            return;
        }

        $this->providerStates[$provider] = $state;

        // Anomaly-withheld candidates ride the same verifier run as exact
        // provider/model targets; the provider itself stays feed-resolved.
        foreach ($anomalousModels as $anomalouModel) {
            $this->fallbackTargets[$provider][] = $anomalouModel;
        }
    }

    /**
     * Run the verifier agent once for every queued fallback target. Returns an
     * error message when the agent phase failed (budget cap, provider error),
     * or null on success.
     *
     * A plain (non-verify) dry run never invokes the agent because its write
     * tool would persist for real. A verify dry run DOES run the agent with
     * end-to-end dry-run persistence threaded through: real fetches, real
     * parsing, and real first-party comparison happen, but nothing is written
     * and nothing is stamped, so the dry verify reports its result from the
     * actual comparison rather than skipping verification.
     */
    private function runAgentPhase(?User $user, bool $dryRun): ?string
    {
        $providers = array_keys($this->fallbackTargets);

        if ($dryRun && ! $this->verifyMode) {
            foreach ($this->providerLevelFallback as $provider) {
                $this->providerStates[$provider]['status'] = self::PROVIDER_FALLBACK_SKIPPED;
            }

            return null;
        }

        // Per-phase ledger: the tools record their real fetch receipts and
        // write outcomes here, so resolution is driven by what the agent
        // actually did rather than by mere prompt completion. Created outside
        // the try so exact-model targets are audited as unverified even when
        // the agent phase aborts before (or while) prompting.
        $priceVerificationRun = new PriceVerificationRun;

        try {
            // The 40-step, fetch-heavy verifier must respect the monthly
            // budget like every other AI entry point.
            $this->aiBudgetGuard->enforce();

            $before = AiModelPrice::query()->count();

            $agent = new PriceFetcherAgent;

            if ($user instanceof User) {
                $agent = $agent->forUser($user);
            }

            $agent = $agent->forScope($this->agentScope(), $providers, $this->providerChecklists(), $priceVerificationRun, dryRun: $dryRun);

            $prompt = 'Verify and correct the catalog pricing for your scoped providers now. Fetch each canonical pricing page first, then upsert the rates you read.';

            $aiSettings = resolve(AiSettings::class);
            $chain = $aiSettings->providerChainWithModel($aiSettings->model());

            $chain === null
                ? $agent->prompt($prompt)
                : $agent->prompt($prompt, provider: $chain);

            // Fold the real per-provider tool outcomes into the audit counters
            // (created/updated/unchanged/locked/rejected), for every provider
            // in the agent's scope — provider-level fallback targets and the
            // anomaly ride-along providers alike.
            foreach (array_keys($this->fallbackTargets) as $provider) {
                $this->applyAgentTally($provider, $priceVerificationRun);
            }

            // Resolve every provider-level fallback target from the ledger's
            // VERIFICATION-GRADE outcomes (a wildcard provider must cover every
            // stored row; an exact-model provider must cover its listed models).
            $this->resolveProviderLevelFallback($priceVerificationRun);

            // Exact-model targets that only ride along a feed-resolved provider
            // (anomaly targets) resolve only through their own provider:model
            // write; provider-level fallbacks are resolved above.
            $this->recordUnverifiedExactModelTargets($priceVerificationRun);

            // Legacy safety net: if the ledger recorded no creations at all yet
            // the catalog still grew (a write path that bypassed the tool),
            // attribute the row delta to the first provider so it is not lost.
            $created = max(0, AiModelPrice::query()->count() - $before);

            if ($created > 0 && $priceVerificationRun->totalCreated() === 0 && $providers !== []) {
                $this->providerStates[$providers[0]]['created'] += $created;
            }

            return null;
        } catch (Throwable $throwable) {
            // Only providers that depended on the verifier fail; providers that
            // rode along for anomaly verification keep their stored values and
            // stay feed-resolved — but their exact-model targets remain
            // unverified, which still degrades the run to partial.
            foreach ($this->providerLevelFallback as $provider) {
                $this->providerStates[$provider]['status'] = self::PROVIDER_FALLBACK_FAILED;
            }

            $this->recordUnverifiedExactModelTargets($priceVerificationRun);

            return $throwable->getMessage();
        }
    }

    /**
     * Audit every exact-model verification target that only rides along a
     * feed-resolved provider (an anomaly ride-along): the provider keeps its ok
     * status, but the pair counts as verified ONLY when the ledger holds a
     * verification-grade write for it. Providers queued as provider-level
     * fallbacks are excluded here — {@see resolveProviderLevelFallback()} owns
     * their resolution and their uncovered-model audit.
     */
    private function recordUnverifiedExactModelTargets(PriceVerificationRun $priceVerificationRun): void
    {
        foreach ($this->fallbackTargets as $provider => $models) {
            if (in_array($provider, $this->providerLevelFallback, true)) {
                continue;
            }

            foreach ($models as $model) {
                if (! $priceVerificationRun->modelHasVerifiedWrite($provider, $model)) {
                    $this->unverifiedTargets[] = $provider.':'.$model;
                }
            }
        }
    }

    /**
     * Resolve each provider-level fallback target from the ledger, folding any
     * uncovered models into {@see $unverifiedTargets} (capped for the audit).
     *
     * - A WILDCARD target (empty model list) resolves only when EVERY existing
     *   catalog row for that provider has a verification-grade outcome; each
     *   uncovered row is audited as `provider:model`. A provider with no stored
     *   rows resolves on a single verification-grade write.
     * - An exact-model provider-level fallback (a model-scoped fallback) resolves
     *   only when each of its listed models has a verification-grade outcome.
     */
    private function resolveProviderLevelFallback(PriceVerificationRun $priceVerificationRun): void
    {
        foreach ($this->providerLevelFallback as $provider) {
            $models = $this->fallbackTargets[$provider] ?? [];

            $uncovered = $models === []
                ? $this->uncoveredStoredModels($provider, $priceVerificationRun)
                : array_values(array_filter(
                    $models,
                    fn (string $model): bool => ! $priceVerificationRun->modelHasVerifiedWrite($provider, $model),
                ));

            $resolved = $models === []
                // A wildcard provider with no stored rows resolves on any
                // verification-grade write; with stored rows, only full coverage.
                ? ($this->providerStoredModels($provider) === [] ? $priceVerificationRun->providerHasVerifiedWrite($provider) : $uncovered === [])
                : $uncovered === [];

            foreach ($uncovered as $model) {
                $this->unverifiedTargets[] = $provider.':'.$model;
            }

            $this->providerStates[$provider]['status'] = $resolved
                ? self::PROVIDER_FALLBACK
                : self::PROVIDER_FALLBACK_FAILED;
        }
    }

    /**
     * The currently-stored catalog models for a provider that lack a
     * verification-grade outcome this run.
     *
     * @return list<string>
     */
    private function uncoveredStoredModels(string $provider, PriceVerificationRun $priceVerificationRun): array
    {
        return array_values(array_filter(
            $this->providerStoredModels($provider),
            fn (string $model): bool => ! $priceVerificationRun->modelHasVerifiedWrite($provider, $model),
        ));
    }

    /**
     * Every model identifier currently stored for the given canonical provider.
     *
     * @return list<string>
     */
    private function providerStoredModels(string $provider): array
    {
        return AiModelPrice::query()
            ->where('provider', $provider)
            ->orderBy('model')
            ->pluck('model')
            ->all();
    }

    /**
     * Fold the verifier's real per-provider write outcomes into that provider's
     * audit counters. Called for every provider in the agent's scope.
     */
    private function applyAgentTally(string $provider, PriceVerificationRun $priceVerificationRun): void
    {
        $counts = $priceVerificationRun->outcomesFor($provider);

        if ($counts === []) {
            return;
        }

        $this->providerState($provider, self::PROVIDER_OK);

        $this->providerStates[$provider]['created'] += ($counts[WriteOutcome::Created->value] ?? 0) + ($counts[WriteOutcome::WouldCreate->value] ?? 0);
        $this->providerStates[$provider]['updated'] += ($counts[WriteOutcome::Updated->value] ?? 0) + ($counts[WriteOutcome::WouldUpdate->value] ?? 0);
        $this->providerStates[$provider]['unchanged'] += $counts[WriteOutcome::Unchanged->value] ?? 0;
        $this->providerStates[$provider]['locked'] += $counts[WriteOutcome::Locked->value] ?? 0;
        $this->providerStates[$provider]['rejected'] += ($counts[WriteOutcome::Rejected->value] ?? 0) + ($counts[WriteOutcome::RejectedAnomalous->value] ?? 0);

        // In verify mode an agent Update means the first-party page disagreed
        // with the value the feed just synced: that is a discrepancy the run
        // records per provider.
        if ($this->verifyMode) {
            $this->providerStates[$provider]['discrepancies'] += ($counts[WriteOutcome::Updated->value] ?? 0) + ($counts[WriteOutcome::WouldUpdate->value] ?? 0);
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
    private function agentScope(): RefreshScope
    {
        $targets = [];

        foreach ($this->fallbackTargets as $provider => $models) {
            $targets[$provider] = $models === [] ? null : $models;
        }

        return RefreshScope::forTargets($targets);
    }

    /**
     * Per-provider model checklists for the verifier's targeted instructions.
     * Only WILDCARD providers (empty target list) that already have stored rows
     * contribute a checklist of those stored models, so the agent is told to
     * re-confirm each one WITHOUT the scope narrowing (new models stay
     * writable). Exact-model targets are shaped by the scope itself, so they are
     * omitted here to avoid a redundant list.
     *
     * @return array<string, list<string>>
     */
    private function providerChecklists(): array
    {
        $checklists = [];

        foreach ($this->fallbackTargets as $provider => $models) {
            if ($models !== []) {
                continue;
            }

            $stored = $this->providerStoredModels($provider);

            if ($stored !== []) {
                $checklists[$provider] = $stored;
            }
        }

        return $checklists;
    }

    /**
     * Queue every requested provider for verifier fallback, honoring the
     * eligibility policy unless `$fallbackAll` forces it (agent source).
     *
     * @param  list<string>  $requested
     */
    private function queueAllForFallback(
        array $requested,
        RefreshScope $refreshScope,
        string $failureStatus,
        bool $fallbackAll,
        bool $fallbackAllowed = true,
    ): void {
        foreach ($requested as $provider) {
            $eligible = $fallbackAll || ($fallbackAllowed && $this->isFallbackEligible($provider, $refreshScope));

            if ($eligible) {
                $this->queueFallback($provider, $refreshScope);
            } else {
                $this->providerState($provider, $failureStatus);
            }
        }
    }

    /**
     * Mark one provider as failed at the feed stage, escalating to fallback
     * when the source and eligibility policy allow it.
     */
    private function resolveProviderFailure(
        string $provider,
        RefreshScope $refreshScope,
        string $failureStatus,
        bool $fallbackAllowed,
    ): void {
        if ($fallbackAllowed && $this->isFallbackEligible($provider, $refreshScope)) {
            $this->queueFallback($provider, $refreshScope);

            return;
        }

        $this->providerStates[$provider] = [
            ...$this->providerState($provider, $failureStatus),
            'status' => $failureStatus,
        ];
    }

    private function queueFallback(string $provider, RefreshScope $refreshScope): void
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
     * Promote every scoped provider to a full provider-level verification
     * target for the agent phase. Providers the feed could not resolve are
     * already queued (skip them); a feed-resolved provider — or one riding along
     * only for an anomaly — is widened to verify its whole GA catalog (or its
     * scope-pinned models when the scope names specific ones) and made to depend
     * on a real agent write, so a provider the verifier never confirms degrades
     * the run to partial exactly like any other zero-outcome fallback target.
     *
     * This applies identically to a verify dry run: the agent still runs (with
     * dry-run persistence), so a scoped provider is queued as a real
     * verification target and resolves only from the actual first-party
     * comparison — never a blanket "skipped" status.
     *
     * @param  list<string>  $requested
     */
    private function queueAllScopedForVerification(array $requested, RefreshScope $refreshScope): void
    {
        foreach ($requested as $provider) {
            if (in_array($provider, $this->providerLevelFallback, true)) {
                continue;
            }

            $this->fallbackTargets[$provider] = $refreshScope->modelsFor($provider) ?? [];
            $this->providerLevelFallback[] = $provider;

            $this->providerState($provider, self::PROVIDER_FALLBACK_SKIPPED);
            $this->providerStates[$provider]['status'] = self::PROVIDER_FALLBACK_SKIPPED;
        }
    }

    /**
     * Core providers always qualify for verifier fallback; Groq, Cohere, and
     * OpenRouter qualify only when the caller explicitly scoped providers.
     */
    private function isFallbackEligible(string $provider, RefreshScope $refreshScope): bool
    {
        return in_array($provider, self::CORE_FALLBACK_PROVIDERS, true) || $refreshScope->isBounded();
    }

    /**
     * Whether the adapter flagged this provider's entire entry as malformed
     * (its model collection is missing or not an object) — the only
     * model-collection-level rejection that escalates to fallback.
     */
    private function isMalformedProvider(ProviderPricingResult $providerPricingResult): bool
    {
        return array_any($providerPricingResult->rejections, fn (PricingRejection $pricingRejection): bool => $pricingRejection->code === PricingRejection::MALFORMED_PROVIDER);
    }

    /**
     * Providers still unresolved after an unexpected orchestration failure.
     *
     * @param  list<string>  $requested
     */
    private function failRemainingProviders(array $requested): void
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
     * Fold adapter rejection codes into the provider's compact audit entry.
     *
     * @param  list<PricingRejection>  $rejections
     */
    private function recordRejectionCodes(string $provider, array $rejections): void
    {
        $state = $this->providerState($provider, self::PROVIDER_OK);

        foreach ($rejections as $rejection) {
            $state['rejections'][$rejection->code] = ($state['rejections'][$rejection->code] ?? 0) + 1;
        }

        $this->providerStates[$provider] = $state;
    }

    /**
     * Fetch (or initialize) the mutable per-provider counter state.
     *
     * @return array{status: string, created: int, updated: int, unchanged: int, locked: int, rejected: int, anomalous: int, tiered: int, discrepancies: int, rejections: array<string, int>}
     */
    private function providerState(string $provider, string $initialStatus): array
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
            'rejections' => [],
        ];
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
        string $mode,
        AiPriceRefreshRun $aiPriceRefreshRun,
        array $requested,
        ?string $modelsDevStatus,
        ?string $errorMessage,
    ): RefreshReport {
        $succeeded = 0;
        $totals = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'locked' => 0, 'rejected' => 0, 'tiered' => 0];
        $providerResults = [];

        foreach ($this->providerStates as $provider => $state) {
            if (in_array($state['status'], [self::PROVIDER_OK, self::PROVIDER_FALLBACK], true)) {
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
        $auditTargets = $this->cappedUnverifiedTargets();

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
            'fallback_targets' => $this->flattenFallbackTargets(),
            'unverified_targets' => $auditTargets === [] ? null : $auditTargets,
            'provider_results' => $providerResults,
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
            fallbackProviders: array_keys($this->fallbackTargets),
            errorMessage: $errorMessage,
            mode: $mode,
        );
    }

    /**
     * The unverified `provider:model` targets for the audit row, de-duplicated
     * and capped at {@see UNVERIFIED_TARGETS_AUDIT_CAP} entries with a trailing
     * `+N more` marker so a wide provider-wide coverage gap cannot bloat the row.
     *
     * @return list<string>
     */
    private function cappedUnverifiedTargets(): array
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
    private function flattenFallbackTargets(): array
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
