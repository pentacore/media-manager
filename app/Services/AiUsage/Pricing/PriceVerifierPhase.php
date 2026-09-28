<?php

declare(strict_types=1);

namespace App\Services\AiUsage\Pricing;

use App\Ai\Agents\PriceFetcherAgent;
use App\Ai\AiRunAttribution;
use App\Models\AiModelPrice;
use App\Models\User;
use App\Services\AiBudget\AiBudgetGuard;
use App\Services\AiUsage\Pricing\Data\WriteOutcome;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Citation;
use Laravel\Ai\Responses\Data\UrlCitation;
use Throwable;

/**
 * The verifier half of a price refresh: one bounded PriceFetcherAgent run
 * over every queued fallback target, then ledger-driven resolution of each
 * target from the agent's verification-grade write outcomes.
 */
class PriceVerifierPhase
{
    public function __construct(
        private readonly AiBudgetGuard $aiBudgetGuard,
        private readonly PriceRefreshFallbackQueue $priceRefreshFallbackQueue,
    ) {}

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
    public function run(PriceRefreshLedger $priceRefreshLedger, ?User $user, bool $dryRun): ?string
    {
        $providers = array_keys($priceRefreshLedger->fallbackTargets);

        if ($dryRun && ! $priceRefreshLedger->verifyMode) {
            foreach ($priceRefreshLedger->providerLevelFallback as $provider) {
                $priceRefreshLedger->providerStates[$provider]['status'] = PriceRefreshLedger::PROVIDER_FALLBACK_SKIPPED;
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

            $agent = new PriceFetcherAgent()->forScope($priceRefreshLedger->agentScope(), $providers, $this->providerChecklists($priceRefreshLedger), $priceVerificationRun, dryRun: $dryRun);

            $prompt = 'Verify and correct the catalog pricing for your scoped providers now. Fetch each canonical pricing page first, then upsert the rates you read.';

            // Queued refreshes have no authenticated user, so attribute the
            // verifier's usage to whoever triggered the run.
            $agentResponse = resolve(AiRunAttribution::class)->during($user, fn (): AgentResponse => $agent->prompt($prompt));

            $priceRefreshLedger->sourceCitations = $agentResponse->meta->citations
                ->filter(fn (Citation $citation): bool => $citation instanceof UrlCitation)
                ->map(fn (UrlCitation $urlCitation): array => ['url' => $urlCitation->url, 'title' => $urlCitation->title])
                ->unique('url')
                ->values()
                ->all();

            // Fold the real per-provider tool outcomes into the audit counters
            // (created/updated/unchanged/locked/rejected), for every provider
            // in the agent's scope — provider-level fallback targets and the
            // anomaly ride-along providers alike.
            foreach (array_keys($priceRefreshLedger->fallbackTargets) as $provider) {
                $this->applyAgentTally($priceRefreshLedger, $provider, $priceVerificationRun);
            }

            // Resolve every provider-level fallback target from the ledger's
            // VERIFICATION-GRADE outcomes (a wildcard provider must cover every
            // stored row; an exact-model provider must cover its listed models).
            $this->resolveProviderLevelFallback($priceRefreshLedger, $priceVerificationRun);

            // Exact-model targets that only ride along a feed-resolved provider
            // (anomaly targets) resolve only through their own provider:model
            // write; provider-level fallbacks are resolved above.
            $this->recordUnverifiedExactModelTargets($priceRefreshLedger, $priceVerificationRun);

            // Legacy safety net: if the ledger recorded no creations at all yet
            // the catalog still grew (a write path that bypassed the tool),
            // attribute the row delta to the first provider so it is not lost.
            $created = max(0, AiModelPrice::query()->count() - $before);

            if ($created > 0 && $priceVerificationRun->totalCreated() === 0 && $providers !== []) {
                $priceRefreshLedger->providerStates[$providers[0]]['created'] += $created;
            }

            return null;
        } catch (Throwable $throwable) {
            // Only providers that depended on the verifier fail; providers that
            // rode along for anomaly verification keep their stored values and
            // stay feed-resolved — but their exact-model targets remain
            // unverified, which still degrades the run to partial.
            foreach ($priceRefreshLedger->providerLevelFallback as $provider) {
                $priceRefreshLedger->providerStates[$provider]['status'] = PriceRefreshLedger::PROVIDER_FALLBACK_FAILED;
            }

            $this->recordUnverifiedExactModelTargets($priceRefreshLedger, $priceVerificationRun);

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
    private function recordUnverifiedExactModelTargets(PriceRefreshLedger $priceRefreshLedger, PriceVerificationRun $priceVerificationRun): void
    {
        foreach ($priceRefreshLedger->fallbackTargets as $provider => $models) {
            if (in_array($provider, $priceRefreshLedger->providerLevelFallback, true)) {
                continue;
            }

            foreach ($models as $model) {
                if (! $priceVerificationRun->modelHasVerifiedWrite($provider, $model)) {
                    $priceRefreshLedger->unverifiedTargets[] = $provider.':'.$model;
                }
            }
        }
    }

    /**
     * Resolve each provider-level fallback target from the ledger, folding any
     * uncovered models into {@see PriceRefreshLedger::$unverifiedTargets} (capped
     * for the audit).
     *
     * - A WILDCARD target (empty model list) resolves only when EVERY existing
     *   catalog row for that provider has a verification-grade outcome; each
     *   uncovered row is audited as `provider:model`. A provider with no stored
     *   rows resolves on a single verification-grade write.
     * - An exact-model provider-level fallback (a model-scoped fallback) resolves
     *   only when each of its listed models has a verification-grade outcome.
     */
    private function resolveProviderLevelFallback(PriceRefreshLedger $priceRefreshLedger, PriceVerificationRun $priceVerificationRun): void
    {
        foreach ($priceRefreshLedger->providerLevelFallback as $provider) {
            $models = $priceRefreshLedger->fallbackTargets[$provider] ?? [];

            $uncovered = $models === []
                ? $this->uncoveredStoredModels($provider, $priceVerificationRun)
                : array_values(array_filter(
                    $models,
                    fn (string $model): bool => ! $priceVerificationRun->modelHasVerifiedWrite($provider, $model),
                ));

            $resolved = $models === []
                // A wildcard provider with no stored rows resolves on any
                // verification-grade write; with stored rows, only full coverage.
                ? ($this->priceRefreshFallbackQueue->storedModels($provider) === [] ? $priceVerificationRun->providerHasVerifiedWrite($provider) : $uncovered === [])
                : $uncovered === [];

            foreach ($uncovered as $model) {
                $priceRefreshLedger->unverifiedTargets[] = $provider.':'.$model;
            }

            $priceRefreshLedger->providerStates[$provider]['status'] = $resolved
                ? PriceRefreshLedger::PROVIDER_FALLBACK
                : PriceRefreshLedger::PROVIDER_FALLBACK_FAILED;
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
            $this->priceRefreshFallbackQueue->storedModels($provider),
            fn (string $model): bool => ! $priceVerificationRun->modelHasVerifiedWrite($provider, $model),
        ));
    }

    /**
     * Fold the verifier's real per-provider write outcomes into that provider's
     * audit counters. Called for every provider in the agent's scope.
     */
    private function applyAgentTally(PriceRefreshLedger $priceRefreshLedger, string $provider, PriceVerificationRun $priceVerificationRun): void
    {
        $counts = $priceVerificationRun->outcomesFor($provider);

        if ($counts === []) {
            return;
        }

        $priceRefreshLedger->providerState($provider, PriceRefreshLedger::PROVIDER_OK);

        $priceRefreshLedger->providerStates[$provider]['created'] += ($counts[WriteOutcome::Created->value] ?? 0) + ($counts[WriteOutcome::WouldCreate->value] ?? 0);
        $priceRefreshLedger->providerStates[$provider]['updated'] += ($counts[WriteOutcome::Updated->value] ?? 0) + ($counts[WriteOutcome::WouldUpdate->value] ?? 0);
        $priceRefreshLedger->providerStates[$provider]['unchanged'] += $counts[WriteOutcome::Unchanged->value] ?? 0;
        $priceRefreshLedger->providerStates[$provider]['locked'] += $counts[WriteOutcome::Locked->value] ?? 0;
        $priceRefreshLedger->providerStates[$provider]['rejected'] += ($counts[WriteOutcome::Rejected->value] ?? 0) + ($counts[WriteOutcome::RejectedAnomalous->value] ?? 0);
        $priceRefreshLedger->providerStates[$provider]['create_disabled'] += $counts[WriteOutcome::CreateDisabled->value] ?? 0;

        // In verify mode an agent Update means the first-party page disagreed
        // with the value the feed just synced: that is a discrepancy the run
        // records per provider.
        if ($priceRefreshLedger->verifyMode) {
            $priceRefreshLedger->providerStates[$provider]['discrepancies'] += ($counts[WriteOutcome::Updated->value] ?? 0) + ($counts[WriteOutcome::WouldUpdate->value] ?? 0);
        }
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
    private function providerChecklists(PriceRefreshLedger $priceRefreshLedger): array
    {
        $checklists = [];

        foreach ($priceRefreshLedger->fallbackTargets as $provider => $models) {
            if ($models !== []) {
                continue;
            }

            $stored = $this->priceRefreshFallbackQueue->storedModels($provider);

            if ($stored !== []) {
                $checklists[$provider] = $stored;
            }
        }

        return $checklists;
    }
}
