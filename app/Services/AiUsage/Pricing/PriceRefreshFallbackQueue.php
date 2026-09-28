<?php

declare(strict_types=1);

namespace App\Services\AiUsage\Pricing;

use App\Models\AiModelPrice;

/**
 * Decides, per provider, whether a price refresh hands it to the verifier
 * agent or settles it without one, and records that on the run's ledger.
 */
class PriceRefreshFallbackQueue
{
    public function __construct(private readonly InUsePricingModels $inUsePricingModels) {}

    /**
     * Queue every requested provider for verifier fallback, or record the
     * failure status on each when the source does not allow fallback.
     *
     * @param  list<string>  $requested
     */
    public function queueAll(
        PriceRefreshLedger $priceRefreshLedger,
        array $requested,
        RefreshScope $refreshScope,
        string $failureStatus,
        bool $fallbackAllowed = true,
    ): void {
        foreach ($requested as $provider) {
            if ($fallbackAllowed) {
                $this->queue($priceRefreshLedger, $provider, $refreshScope);
            } else {
                $priceRefreshLedger->providerState($provider, $failureStatus);
            }
        }
    }

    /**
     * Mark one provider as failed at the feed stage, escalating to fallback
     * when the source allows it.
     */
    public function resolveProviderFailure(
        PriceRefreshLedger $priceRefreshLedger,
        string $provider,
        RefreshScope $refreshScope,
        string $failureStatus,
        bool $fallbackAllowed,
    ): void {
        if ($fallbackAllowed) {
            $this->queue($priceRefreshLedger, $provider, $refreshScope);

            return;
        }

        $priceRefreshLedger->providerStates[$provider] = [
            ...$priceRefreshLedger->providerState($provider, $failureStatus),
            'status' => $failureStatus,
        ];
    }

    /**
     * Queue a provider for verifier fallback, settling it as succeeded instead
     * when there is nothing the verifier could refresh for it.
     */
    public function queue(PriceRefreshLedger $priceRefreshLedger, string $provider, RefreshScope $refreshScope): void
    {
        if ($this->hasNothingToRefresh($provider, $refreshScope)) {
            $priceRefreshLedger->resolveWithNothingToRefresh($provider);

            return;
        }

        $priceRefreshLedger->enqueueFallback($provider, $refreshScope);
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
    public function queueAllScopedForVerification(PriceRefreshLedger $priceRefreshLedger, array $requested, RefreshScope $refreshScope): void
    {
        foreach ($requested as $provider) {
            if (in_array($provider, $priceRefreshLedger->providerLevelFallback, true)) {
                continue;
            }

            if ($this->hasNothingToRefresh($provider, $refreshScope)) {
                $priceRefreshLedger->resolveWithNothingToRefresh($provider);

                continue;
            }

            $priceRefreshLedger->fallbackTargets[$provider] = $refreshScope->modelsFor($provider) ?? [];
            $priceRefreshLedger->providerLevelFallback[] = $provider;

            $priceRefreshLedger->providerState($provider, PriceRefreshLedger::PROVIDER_FALLBACK_SKIPPED);
            $priceRefreshLedger->providerStates[$provider]['status'] = PriceRefreshLedger::PROVIDER_FALLBACK_SKIPPED;
        }
    }

    /**
     * Every model identifier currently stored for the given canonical provider.
     *
     * @return list<string>
     */
    public function storedModels(string $provider): array
    {
        return AiModelPrice::query()
            ->where('provider', $provider)
            ->orderBy('model')
            ->pluck('model')
            ->all();
    }

    /**
     * Whether a provider-wide verifier target would be pointless: the provider
     * is update-only (off the auto-create list) with no stored rows and no
     * in-use models, so the agent could neither update nor create anything for
     * it. Model-pinned scopes are never short-circuited — their exact targets
     * stay audited.
     */
    private function hasNothingToRefresh(string $provider, RefreshScope $refreshScope): bool
    {
        return $refreshScope->modelsFor($provider) === null
            && ! $refreshScope->allowsCreate($provider)
            && $this->storedModels($provider) === []
            && $this->inUsePricingModels->forProvider($provider) === [];
    }
}
