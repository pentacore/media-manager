<?php

declare(strict_types=1);

namespace App\Services\AiUsage\Pricing;

use App\Models\AiUsageRecord;
use App\Settings\AiSettings;
use App\Settings\DecisionAgentSettings;
use InvalidArgumentException;
use Laravel\Ai\Ai;
use LogicException;

/**
 * The models the application actually calls, keyed by canonical provider.
 *
 * An update-only provider (one off the auto-create list) never adds the models
 * it newly reports — except the ones returned here, so an OpenRouter model the
 * app classifies or reranks with still gets priced without importing the whole
 * resold catalog. A model is in use when it appears in AI usage history or is
 * picked in AI settings:
 *
 * - every distinct provider/model pair in `ai_usage_records`;
 * - the classification and reranking models (the provider's default model when
 *   none is set), only while that provider has an API key, since the callers
 *   skip a keyless provider;
 * - the chat, title, sub-agent, decision-agent and price updater selections,
 *   each under its own provider.
 *
 * The map is built once per instance; bound scoped so a long-running worker
 * rebuilds it for every request or job.
 */
final class InUsePricingModels
{
    /**
     * Canonical provider => in-use model identifiers, or null until built.
     *
     * @var array<string, list<string>>|null
     */
    private ?array $providerModels = null;

    public function __construct(
        private readonly AiSettings $aiSettings,
        private readonly DecisionAgentSettings $decisionAgentSettings,
    ) {}

    /**
     * Whether the given provider/model pair is in use.
     */
    public function contains(string $provider, string $model): bool
    {
        return in_array($model, $this->forProvider($provider), true);
    }

    /**
     * The in-use models for a provider (upstream or canonical spelling).
     *
     * @return list<string>
     */
    public function forProvider(string $provider): array
    {
        $canonical = RefreshScope::canonicalProvider($provider);

        if ($canonical === null) {
            return [];
        }

        return $this->providerModels()[$canonical] ?? [];
    }

    /**
     * @return array<string, list<string>>
     */
    private function providerModels(): array
    {
        if ($this->providerModels !== null) {
            return $this->providerModels;
        }

        $pairs = AiUsageRecord::query()
            ->whereNotNull('provider')
            ->whereNotNull('model')
            ->distinct()
            ->orderBy('provider')
            ->orderBy('model')
            ->get(['provider', 'model'])
            ->map(fn (AiUsageRecord $aiUsageRecord): array => [$aiUsageRecord->provider, $aiUsageRecord->model])
            ->all();

        $classificationProvider = $this->aiSettings->classificationProvider();

        if ($this->hasApiKey($classificationProvider)) {
            $pairs[] = [$classificationProvider, $this->aiSettings->classificationModel() ?? $this->defaultClassificationModel($classificationProvider)];
        }

        $rerankingProvider = $this->aiSettings->rerankingProvider();

        if ($this->hasApiKey($rerankingProvider)) {
            $pairs[] = [$rerankingProvider, $this->aiSettings->rerankingModel() ?? $this->defaultRerankingModel($rerankingProvider)];
        }

        $titleModel = $this->aiSettings->rawTitleModel();
        $pairs[] = [$this->aiSettings->titleModelProvider(), $titleModel === AiSettings::AUTO_MODEL ? null : $titleModel];

        foreach ([
            $this->aiSettings->chatSelection(),
            $this->aiSettings->subAgentSelection(),
            $this->aiSettings->priceUpdaterSelection(),
            $this->decisionAgentSettings->selection(),
        ] as $modelSelection) {
            $pairs[] = [$modelSelection->provider, $modelSelection->model];
        }

        $providerModels = [];

        foreach ($pairs as [$provider, $model]) {
            $canonical = RefreshScope::canonicalProvider((string) $provider);
            $model = trim((string) $model);

            if ($canonical === null || $model === '') {
                continue;
            }

            if (! in_array($model, $providerModels[$canonical] ?? [], true)) {
                $providerModels[$canonical][] = $model;
            }
        }

        return $this->providerModels = $providerModels;
    }

    private function hasApiKey(string $provider): bool
    {
        return filled(config(sprintf('ai.providers.%s.key', $provider)));
    }

    private function defaultClassificationModel(string $provider): ?string
    {
        try {
            return Ai::classificationProvider($provider)->defaultClassificationModel();
        } catch (InvalidArgumentException|LogicException) {
            return null;
        }
    }

    private function defaultRerankingModel(string $provider): ?string
    {
        try {
            return Ai::rerankingProvider($provider)->defaultRerankingModel();
        } catch (InvalidArgumentException|LogicException) {
            return null;
        }
    }
}
