<?php

declare(strict_types=1);

namespace App\Services\AiUsage\Pricing;

use App\Services\AiUsage\Pricing\Data\PricingCatalogResult;
use App\Services\AiUsage\Pricing\Data\PricingRejection;
use App\Services\AiUsage\Pricing\Data\PricingWarning;
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
final readonly class PricingCatalog
{
    public const string SOURCE_OPENROUTER = 'openrouter';

    public const string SOURCE_XAI = 'xai';

    public const string SOURCE_MODELS_DEV = 'models_dev';

    public const string SOURCE_LITELLM = 'litellm';

    public function __construct(
        private ModelsDevPricingClient $modelsDevPricingClient,
        private ModelsDevPricingAdapter $modelsDevPricingAdapter,
        private LiteLlmPricingClient $liteLlmPricingClient,
        private LiteLlmPricingAdapter $liteLlmPricingAdapter,
        private OpenRouterPricingClient $openRouterPricingClient,
        private OpenRouterPricingAdapter $openRouterPricingAdapter,
        private XaiPricingClient $xaiPricingClient,
        private XaiPricingAdapter $xaiPricingAdapter,
        private PricingReconciler $pricingReconciler,
        private AiSettings $aiSettings,
    ) {}

    /**
     * Whether any structured source is switched on.
     */
    public function anySourceEnabled(): bool
    {
        $enabled = $this->enabledSources();

        // A switched-on xAI source with no configured key can never leave
        // `not_configured`, so it must not count as "enabled" here — otherwise
        // an operator who flips xAI on without a key loses the coordinator's
        // quiet FEED_DISABLED fallback path for a source that will never
        // produce data. `fetch()` itself is untouched: it still attempts xAI
        // and records the (quiet) `not_configured` status.
        $enabled[self::SOURCE_XAI] = $enabled[self::SOURCE_XAI] && $this->xaiKeyConfigured();

        return in_array(true, $enabled, true);
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

        foreach ($providerKeys as $providerKey) {
            $reconciled = $this->pricingReconciler->reconcile($providerKey, $modelsDev[$providerKey] ?? null, $liteLlm[$providerKey] ?? null);

            $result = $providerKey === self::SOURCE_XAI
                ? $this->mergeXaiPerModel($xai[$providerKey] ?? null, $reconciled)
                : ($openRouter[$providerKey] ?? $reconciled);

            if ($result instanceof ProviderPricingResult) {
                $providers[$providerKey] = $result;
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

    private function xaiKeyConfigured(): bool
    {
        $key = config('ai.providers.xai.key');

        return is_string($key) && $key !== '';
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
     * Merge xAI API candidates with the reconciled models.dev+LiteLLM `xai`
     * result, per model id rather than per provider: an xAI candidate
     * (canonical id or alias) always wins for the model ids it covers, and the
     * reconciled result fills in every xai model id xAI did not cover. A
     * reconciled conflict or rejection for a model id xAI covered is dropped
     * (xAI settled it); a warning is kept only for a model id that ended up
     * with a candidate. Returns the reconciled result unchanged when xAI
     * produced no candidates (disabled, failed, or an empty response).
     */
    private function mergeXaiPerModel(?ProviderPricingResult $xai, ?ProviderPricingResult $reconciled): ?ProviderPricingResult
    {
        if (! $xai instanceof ProviderPricingResult || $xai->candidates === []) {
            return $reconciled;
        }

        $covered = [];

        foreach ($xai->candidates as $candidate) {
            $covered[$candidate->model] = true;
        }

        $candidates = $xai->candidates;
        $conflicts = [];

        foreach ($reconciled?->candidates ?? [] as $candidate) {
            if (! isset($covered[$candidate->model])) {
                $candidates[] = $candidate;
            }
        }

        foreach ($reconciled?->conflicts ?? [] as $conflict) {
            if (! isset($covered[$conflict])) {
                $conflicts[] = $conflict;
            }
        }

        $settled = [];

        foreach ($candidates as $candidate) {
            $settled[$candidate->model] = true;
        }

        $rejections = array_values(array_filter(
            [...$xai->rejections, ...($reconciled?->rejections ?? [])],
            static fn (PricingRejection $pricingRejection): bool => ! isset($settled[$pricingRejection->model]),
        ));

        $warnings = array_values(array_filter(
            [...$xai->warnings, ...($reconciled?->warnings ?? [])],
            static fn (PricingWarning $pricingWarning): bool => isset($settled[$pricingWarning->model]),
        ));

        return new ProviderPricingResult(
            provider: $xai->provider,
            candidates: $candidates,
            rejections: $rejections,
            warnings: $warnings,
            createSuppressed: $xai->createSuppressed || ($reconciled?->createSuppressed ?? false),
            conflicts: $conflicts,
        );
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
