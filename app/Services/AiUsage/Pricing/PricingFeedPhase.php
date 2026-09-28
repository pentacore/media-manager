<?php

declare(strict_types=1);

namespace App\Services\AiUsage\Pricing;

use App\Enums\PricingSource;
use App\Services\AiUsage\Pricing\Data\ModelPriceCandidate;
use App\Services\AiUsage\Pricing\Data\PricingRejection;
use App\Services\AiUsage\Pricing\Data\ProviderPricingResult;
use App\Services\AiUsage\Pricing\Data\WriteOutcome;
use Illuminate\Database\DetectsLostConnections;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The feed half of a price refresh: fetch every enabled pricing source
 * through PricingCatalog and persist each provider's candidates through
 * AiModelPriceWriter in one transaction per provider, escalating providers
 * the feed could not resolve to the verifier via PriceRefreshFallbackQueue.
 */
class PricingFeedPhase
{
    use DetectsLostConnections;

    /** Models.dev feed status stamped on the run when no source is enabled. */
    public const string FEED_DISABLED = 'disabled';

    public function __construct(
        private readonly PricingCatalog $pricingCatalog,
        private readonly AiModelPriceWriter $aiModelPriceWriter,
        private readonly PriceRefreshFallbackQueue $priceRefreshFallbackQueue,
    ) {}

    /**
     * Fetch and persist every enabled pricing source's slice of the run.
     * Returns the models.dev status (`ok`, `disabled`, or a transport
     * category); on a global source failure, queues fallback (hybrid only)
     * and records the failure message into `$errorMessage`.
     */
    public function run(
        PriceRefreshLedger $priceRefreshLedger,
        string $source,
        RefreshScope $refreshScope,
        array $requested,
        bool $dryRun,
        ?string &$errorMessage,
    ): string {
        $pricingCatalogResult = $this->pricingCatalog->fetch($refreshScope, modelsDevOnly: $source === AiPriceRefreshCoordinator::SOURCE_MODELS_DEV);

        $priceRefreshLedger->sourceStatuses = $pricingCatalogResult->sourceStatuses;
        $modelsDevStatus = $pricingCatalogResult->sourceStatuses[PricingCatalog::SOURCE_MODELS_DEV] ?? self::FEED_DISABLED;

        if ($pricingCatalogResult->unavailable) {
            $errorMessage = $pricingCatalogResult->errorMessage ?? 'No pricing source produced data.';

            $this->priceRefreshFallbackQueue->queueAll(
                $priceRefreshLedger,
                $requested,
                $refreshScope,
                PriceRefreshLedger::PROVIDER_FEED_UNAVAILABLE,
                fallbackAllowed: $source === AiPriceRefreshCoordinator::SOURCE_HYBRID,
            );

            return $modelsDevStatus;
        }

        $results = $pricingCatalogResult->providers;

        foreach ($requested as $provider) {
            $result = $results[$provider] ?? null;

            if ($result === null) {
                $this->priceRefreshFallbackQueue->resolveProviderFailure($priceRefreshLedger, $provider, $refreshScope, PriceRefreshLedger::PROVIDER_MISSING, $source === AiPriceRefreshCoordinator::SOURCE_HYBRID);

                continue;
            }

            if ($this->isMalformedProvider($result)) {
                $priceRefreshLedger->recordRejectionCodes($provider, $result->rejections);
                $this->priceRefreshFallbackQueue->resolveProviderFailure($priceRefreshLedger, $provider, $refreshScope, PriceRefreshLedger::PROVIDER_MALFORMED, $source === AiPriceRefreshCoordinator::SOURCE_HYBRID);

                continue;
            }

            if ($result->candidates === []) {
                // Structurally valid but zero eligible candidates (all
                // deprecated / non-text / out of scope): the feed answered
                // nothing usable, so the provider is incomplete and escalates
                // like a missing provider rather than counting as succeeded.
                $priceRefreshLedger->recordRejectionCodes($provider, $result->rejections);
                $priceRefreshLedger->providerStates[$provider]['rejected'] += count($result->rejections);
                $priceRefreshLedger->providerStates[$provider]['conflicts'] += count($result->conflicts);
                $this->priceRefreshFallbackQueue->resolveProviderFailure($priceRefreshLedger, $provider, $refreshScope, PriceRefreshLedger::PROVIDER_INCOMPLETE, $source === AiPriceRefreshCoordinator::SOURCE_HYBRID);

                continue;
            }

            $this->writeProviderCandidates($priceRefreshLedger, $provider, $result, $refreshScope, $dryRun, $source === AiPriceRefreshCoordinator::SOURCE_HYBRID);
        }

        return $modelsDevStatus;
    }

    /**
     * Persist one provider's candidates inside a single transaction and fold
     * every outcome into the provider's counters.
     */
    private function writeProviderCandidates(
        PriceRefreshLedger $priceRefreshLedger,
        string $provider,
        ProviderPricingResult $providerPricingResult,
        RefreshScope $refreshScope,
        bool $dryRun,
        bool $fallbackAllowed,
    ): void {
        $priceRefreshLedger->recordRejectionCodes($provider, $providerPricingResult->rejections);

        $stateBeforeWrites = $priceRefreshLedger->providerStates[$provider];
        $stateBeforeWrites['rejected'] += count($providerPricingResult->rejections);

        $state = $stateBeforeWrites;
        $state['status'] = PriceRefreshLedger::PROVIDER_OK;

        $anomalousModels = [];

        try {
            DB::transaction(function () use ($providerPricingResult, $refreshScope, $dryRun, &$state, &$anomalousModels): void {
                foreach ($providerPricingResult->candidates as $candidate) {
                    $outcome = $this->aiModelPriceWriter->write(
                        $candidate,
                        $refreshScope,
                        $candidate->source,
                        dryRun: $dryRun,
                        firstPartyVerified: $candidate->source->isFirstPartyApi(),
                    );

                    match ($outcome) {
                        WriteOutcome::Created, WriteOutcome::WouldCreate => $state['created']++,
                        WriteOutcome::Updated, WriteOutcome::WouldUpdate => $state['updated']++,
                        WriteOutcome::Unchanged => $state['unchanged']++,
                        WriteOutcome::Locked => $state['locked']++,
                        WriteOutcome::Rejected => $state['rejected']++,
                        WriteOutcome::RejectedAnomalous => $state['anomalous']++,
                        WriteOutcome::CreateDisabled => $state['create_disabled']++,
                    };

                    if ($outcome === WriteOutcome::RejectedAnomalous) {
                        $anomalousModels[] = $candidate->model;
                    }

                    if ($candidate->tiered && ! in_array($outcome, [WriteOutcome::Rejected, WriteOutcome::RejectedAnomalous, WriteOutcome::CreateDisabled], true)) {
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
                $priceRefreshLedger->providerStates[$provider] = [...$stateBeforeWrites, 'status' => PriceRefreshLedger::PROVIDER_WRITE_FAILED];

                throw $throwable;
            }

            // An isolated per-provider failure rolled this provider's slice
            // back; discard its partial counters. The database is still healthy
            // (this was not a lost connection), so in hybrid the provider is not
            // terminal: escalate it to the first-party verifier exactly like a
            // missing/malformed provider. The ledger then resolves it only if
            // the verifier produces a real write; otherwise it stays unresolved.
            $priceRefreshLedger->writeFailureMessage ??= $throwable->getMessage();

            if ($fallbackAllowed) {
                $priceRefreshLedger->providerStates[$provider] = $stateBeforeWrites;
                $priceRefreshLedger->enqueueFallback($provider, $refreshScope);

                return;
            }

            $priceRefreshLedger->providerStates[$provider] = [...$stateBeforeWrites, 'status' => PriceRefreshLedger::PROVIDER_WRITE_FAILED];

            return;
        }

        $priceRefreshLedger->providerStates[$provider] = $state;
        $priceRefreshLedger->providerStates[$provider]['consensus'] += count(array_filter(
            $providerPricingResult->candidates,
            static fn (ModelPriceCandidate $modelPriceCandidate): bool => $modelPriceCandidate->source === PricingSource::FeedConsensus,
        ));
        $priceRefreshLedger->providerStates[$provider]['conflicts'] += count($providerPricingResult->conflicts);

        // Anomaly-withheld candidates and feed conflicts ride the same
        // verifier run as exact provider/model targets; the provider itself
        // stays feed-resolved.
        foreach ([...$anomalousModels, ...$providerPricingResult->conflicts] as $targetModel) {
            $priceRefreshLedger->fallbackTargets[$provider][] = $targetModel;
        }
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
}
