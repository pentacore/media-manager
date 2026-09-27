<?php

declare(strict_types=1);

namespace App\Services\AiUsage\Pricing;

use App\Services\AiUsage\Pricing\Data\PricingCatalogResult;
use App\Services\AiUsage\Pricing\Data\ProviderPricingResult;
use App\Settings\AiSettings;
use Closure;

/**
 * The single entry point the refresh coordinator uses for structured prices.
 *
 * Fetches every enabled source, adapts each, and picks one result per
 * provider:
 *
 * | provider     | primary          | fallback                               |
 * |--------------|------------------|----------------------------------------|
 * | `openrouter` | OpenRouter API   | models.dev `openrouter` slice           |
 * | `xai`        | xAI pricing API  | models.dev + LiteLLM (reconciled)       |
 * | others       | models.dev + LiteLLM (reconciled)                         |
 *
 * A primary source that is disabled, not configured, or failed falls through
 * to the fallback. Sources fail independently; the catalog is unavailable
 * only when no source produced data. Per-run state only — resolve per run.
 */
final class PricingCatalog
{
    public const string SOURCE_OPENROUTER = 'openrouter';

    public const string SOURCE_XAI = 'xai';

    public const string SOURCE_MODELS_DEV = 'models_dev';

    public const string SOURCE_LITELLM = 'litellm';

    public function __construct(
        private readonly ModelsDevPricingClient $modelsDevPricingClient,
        private readonly ModelsDevPricingAdapter $modelsDevPricingAdapter,
        private readonly LiteLlmPricingClient $liteLlmPricingClient,
        private readonly LiteLlmPricingAdapter $liteLlmPricingAdapter,
        private readonly OpenRouterPricingClient $openRouterPricingClient,
        private readonly OpenRouterPricingAdapter $openRouterPricingAdapter,
        private readonly XaiPricingClient $xaiPricingClient,
        private readonly XaiPricingAdapter $xaiPricingAdapter,
        private readonly PricingReconciler $pricingReconciler,
        private readonly AiSettings $aiSettings,
    ) {}

    /**
     * Whether any structured source is switched on.
     */
    public function anySourceEnabled(): bool
    {
        return in_array(true, $this->enabledSources(), true);
    }

    /**
     * Fetch, adapt, reconcile, and apply precedence. `$modelsDevOnly` is the
     * explicit `models-dev` refresh source: only models.dev is consulted and
     * its settings gate is ignored.
     */
    public function fetch(RefreshScope $refreshScope, bool $modelsDevOnly = false): PricingCatalogResult
    {
        $enabled = $modelsDevOnly
            ? [self::SOURCE_OPENROUTER => false, self::SOURCE_XAI => false, self::SOURCE_MODELS_DEV => true, self::SOURCE_LITELLM => false]
            : $this->enabledSources();

        $statuses = [];
        $errorMessage = null;

        $openRouter = $this->attempt(self::SOURCE_OPENROUTER, $enabled, $statuses, $errorMessage, fn (): array => $this->single(
            $this->openRouterPricingAdapter->adapt($this->openRouterPricingClient->fetch(), $refreshScope),
        ));

        $xai = $this->attempt(self::SOURCE_XAI, $enabled, $statuses, $errorMessage, fn (): array => $this->single(
            $this->xaiPricingAdapter->adapt($this->xaiPricingClient->fetch(), $refreshScope),
        ));

        $modelsDev = $this->attempt(self::SOURCE_MODELS_DEV, $enabled, $statuses, $errorMessage, fn (): array => $this->modelsDevPricingAdapter->adapt(
            $this->modelsDevPricingClient->fetch(),
            $refreshScope,
        ));

        $liteLlm = $this->attempt(self::SOURCE_LITELLM, $enabled, $statuses, $errorMessage, fn (): array => $this->liteLlmPricingAdapter->adapt(
            $this->liteLlmPricingClient->fetch(),
            $refreshScope,
        ));

        $providers = [];

        $providerKeys = array_unique([
            ...array_keys($openRouter ?? []),
            ...array_keys($xai ?? []),
            ...array_keys($modelsDev ?? []),
            ...array_keys($liteLlm ?? []),
        ]);

        foreach ($providerKeys as $provider) {
            $result = $openRouter[$provider]
                ?? $xai[$provider]
                ?? $this->pricingReconciler->reconcile($provider, $modelsDev[$provider] ?? null, $liteLlm[$provider] ?? null);

            if ($result instanceof ProviderPricingResult) {
                $providers[$provider] = $result;
            }
        }

        return new PricingCatalogResult(
            providers: $providers,
            sourceStatuses: $statuses,
            unavailable: ! in_array(PricingCatalogResult::STATUS_OK, $statuses, true),
            errorMessage: $errorMessage,
        );
    }

    /**
     * @return array<string, bool>
     */
    private function enabledSources(): array
    {
        return [
            self::SOURCE_OPENROUTER => $this->aiSettings->openRouterPricingEnabled(),
            self::SOURCE_XAI => $this->aiSettings->xaiPricingEnabled(),
            self::SOURCE_MODELS_DEV => $this->aiSettings->modelsDevPricingEnabled(),
            self::SOURCE_LITELLM => $this->aiSettings->liteLlmPricingEnabled(),
        ];
    }

    /**
     * Run one source, recording its status. Returns its provider results, or
     * null when it is disabled or failed.
     *
     * @param  array<string, bool>  $enabled
     * @param  array<string, string>  $statuses
     * @param  Closure(): array<string, ProviderPricingResult>  $load
     * @return array<string, ProviderPricingResult>|null
     */
    private function attempt(string $source, array $enabled, array &$statuses, ?string &$errorMessage, Closure $load): ?array
    {
        if (! ($enabled[$source] ?? false)) {
            $statuses[$source] = PricingCatalogResult::STATUS_DISABLED;

            return null;
        }

        try {
            $results = $load();
        } catch (PricingTransportException $pricingTransportException) {
            $statuses[$source] = $pricingTransportException->category;

            if ($pricingTransportException->category !== PricingTransportException::CATEGORY_NOT_CONFIGURED) {
                $errorMessage ??= $pricingTransportException->getMessage();
            }

            return null;
        }

        $statuses[$source] = PricingCatalogResult::STATUS_OK;

        return $results;
    }

    /**
     * Wrap a single-provider adapter result as a provider-keyed map.
     *
     * @return array<string, ProviderPricingResult>
     */
    private function single(?ProviderPricingResult $providerPricingResult): array
    {
        return $providerPricingResult instanceof ProviderPricingResult
            ? [$providerPricingResult->provider => $providerPricingResult]
            : [];
    }
}
