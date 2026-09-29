<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Ai\ModelCatalog;
use App\Ai\ProviderCapabilities;
use App\Enums\AiMode;
use App\Enums\AiReasoningLevel;
use App\Enums\OpenRouterSort;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateAiSettingsRequest;
use App\Jobs\ReembedLibrary;
use App\Services\AiBudget\AiBudgetGuard;
use App\Services\AiBudget\UnpricedModelDetector;
use App\Settings\AiSettings;
use App\Settings\OpenRouterSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Ai\Contracts\Providers\SupportsCodeExecution;
use Laravel\Ai\Contracts\Providers\SupportsToolSearch;
use Laravel\Ai\Enums\Lab;

class AiSettingsController extends Controller
{
    public function index(
        AiSettings $aiSettings,
        AiBudgetGuard $aiBudgetGuard,
        ProviderCapabilities $providerCapabilities,
        UnpricedModelDetector $unpricedModelDetector,
        ModelCatalog $modelCatalog,
        OpenRouterSettings $openRouterSettings,
    ): Response {
        return Inertia::render('Admin/AiSettings/Index', [
            'settings' => [
                'mode' => $aiSettings->mode()->value,
                'model' => $aiSettings->model(),
                'title_model' => $aiSettings->rawTitleModel(),
                'soft_budget_usd' => $aiSettings->softBudgetUsd(),
                'hard_budget_usd' => $aiSettings->hardBudgetUsd(),
                'advisor_reasoning_level' => $aiSettings->advisorReasoningLevel(),
                'chat_timeout' => $aiSettings->chatTimeout(),
                'failover_provider' => $aiSettings->failoverProvider()?->value ?? 'none',
                'models_dev_pricing_enabled' => $aiSettings->modelsDevPricingEnabled(),
                'openrouter_pricing_enabled' => $aiSettings->openRouterPricingEnabled(),
                'litellm_pricing_enabled' => $aiSettings->liteLlmPricingEnabled(),
                'xai_pricing_enabled' => $aiSettings->xaiPricingEnabled(),
                'xai_pricing_key_configured' => filled(config('ai.providers.xai.key')),
                'rate_limits_enforced' => $aiSettings->rateLimitsEnforced(),
                'ignored_pricing_providers' => $this->canonicalPricingProviders($aiSettings->ignoredPricingProviders()),
                'auto_create_pricing_providers' => $this->canonicalPricingProviders($aiSettings->autoCreatePricingProviders()),
                'classification_provider' => $aiSettings->classificationProvider(),
                'classification_model' => $aiSettings->classificationModel(),
                'decision_gate_enabled' => $aiSettings->decisionGateEnabled(),
                'decision_gate_threshold' => $aiSettings->decisionGateThreshold(),
                'subtitle_triage_enabled' => $aiSettings->subtitleTriageEnabled(),
                'subtitle_triage_threshold' => $aiSettings->subtitleTriageThreshold(),
                'chat_routing_enabled' => $aiSettings->chatRoutingEnabled(),
                'reranking_provider' => $aiSettings->rerankingProvider(),
                'reranking_model' => $aiSettings->rerankingModel(),
                'embeddings_provider' => $aiSettings->embeddingsProvider(),
                'embeddings_model' => $aiSettings->embeddingsModel(),
                'sub_agent_model' => $aiSettings->rawSubAgentModel(),
                'price_updater_model' => $aiSettings->rawPriceUpdaterModel(),
                'model_provider' => $aiSettings->modelProvider(),
                'title_model_provider' => $aiSettings->titleModelProvider(),
                // Null while the setting follows the chat selection; otherwise
                // the effective provider (a legacy row without one resolves to
                // ai.default), so the form never guesses it.
                'sub_agent_model_provider' => $aiSettings->rawSubAgentModel() === null ? null : $aiSettings->subAgentSelection()->provider,
                'price_updater_model_provider' => $aiSettings->rawPriceUpdaterModel() === null ? null : $aiSettings->priceUpdaterSelection()->provider,
                'failover_model' => $aiSettings->failoverModel(),
                'openrouter' => [
                    'sort' => $openRouterSettings->sort()?->value,
                    'deny_data_collection' => $openRouterSettings->denyDataCollection(),
                    'allow_fallbacks' => $openRouterSettings->allowFallbacks(),
                    'order' => implode(', ', $openRouterSettings->order()),
                    'ignore' => implode(', ', $openRouterSettings->ignore()),
                ],
            ],
            'budget' => [
                'spend' => round($aiBudgetGuard->currentMonthSpend(), 4),
                'soft' => $aiSettings->softBudgetUsd(),
                'hard' => $aiSettings->hardBudgetUsd(),
                'soft_notified_at' => $aiSettings->softBudgetNotifiedAt(),
            ],
            'unpricedModels' => $unpricedModelDetector->forHardCap(),
            'modes' => AiMode::mapForSelect(labelKey: 'label'),
            'models' => $modelCatalog->modelsByConfiguredProvider(),
            'reasoningLevels' => AiReasoningLevel::mapForSelect(labelKey: 'label'),
            'failoverProviders' => [
                ['value' => 'none', 'label' => 'None'],
                ['value' => Lab::Anthropic->value, 'label' => 'Anthropic'],
                ['value' => Lab::OpenAI->value, 'label' => 'OpenAI'],
                ['value' => Lab::Gemini->value, 'label' => 'Gemini'],
                ['value' => Lab::Groq->value, 'label' => 'Groq'],
                ['value' => Lab::Mistral->value, 'label' => 'Mistral'],
                ['value' => Lab::OpenRouter->value, 'label' => 'OpenRouter'],
            ],
            'openRouterSorts' => array_map(
                static fn (OpenRouterSort $openRouterSort): array => ['value' => $openRouterSort->value, 'label' => $openRouterSort->label()],
                OpenRouterSort::cases(),
            ),
            'pricingProviders' => $this->pricingProviders(),
            'classificationProviders' => [
                ['value' => 'openrouter', 'label' => 'OpenRouter'],
                ['value' => 'typesafe', 'label' => 'TypeSafe'],
            ],
            'rerankingProviders' => [
                ['value' => 'cohere', 'label' => 'Cohere'],
                ['value' => 'jina', 'label' => 'Jina'],
                ['value' => 'openrouter', 'label' => 'OpenRouter'],
            ],
            'embeddings' => [
                'stale' => $aiSettings->embeddingsStale(),
                'indexed_with' => $aiSettings->embeddingsIndexedWith(),
                'signature' => $aiSettings->embeddingsSignature(),
                'reembedding' => Cache::has(ReembedLibrary::RUNNING_CACHE_KEY),
            ],
            'embeddingsProviders' => array_map(
                fn (string $provider): array => ['value' => $provider, 'label' => $this->providerLabel($provider)],
                $modelCatalog->embeddingProviders(),
            ),
            'providerKeys' => collect(['openrouter', 'typesafe', 'cohere', 'jina'])
                ->mapWithKeys(fn (string $provider): array => [$provider => filled(config(sprintf('ai.providers.%s.key', $provider)))])
                ->all(),
            'advancedTools' => [
                'tool_search' => $providerCapabilities->everyProviderSupports(SupportsToolSearch::class, $aiSettings->chatSelection()),
                'code_execution' => $providerCapabilities->everyProviderSupports(SupportsCodeExecution::class, $aiSettings->priceUpdaterSelection()),
            ],
        ]);
    }

    /**
     * Canonical pricing providers offered as ignore-list and auto-create
     * options, in configured catalog order with human-friendly labels.
     *
     * @return list<array{value: string, label: string}>
     */
    private function pricingProviders(): array
    {
        /** @var array<string, string> $map */
        $map = config('mediamanager.ai.pricing.providers', []);

        return collect(array_values($map))
            ->unique()
            ->values()
            ->map(fn (string $provider): array => [
                'value' => $provider,
                'label' => $this->providerLabel($provider),
            ])
            ->all();
    }

    /**
     * The human-friendly label for a provider id, matching the labels shown
     * across every other provider list on this page; an unlisted provider
     * falls back to `ucfirst()`.
     */
    private function providerLabel(string $provider): string
    {
        $labels = [
            'openai' => 'OpenAI',
            'anthropic' => 'Anthropic',
            'gemini' => 'Gemini',
            'xai' => 'xAI',
            'deepseek' => 'DeepSeek',
            'mistral' => 'Mistral',
            'groq' => 'Groq',
            'cohere' => 'Cohere',
            'openrouter' => 'OpenRouter',
        ];

        return $labels[$provider] ?? ucfirst($provider);
    }

    /**
     * Map a provider list that may use upstream spellings (for example an env
     * default naming `google`) onto the canonical identities the checkboxes and
     * validation use, dropping unknown entries so a save round-trips cleanly.
     *
     * @param  list<string>  $providers
     * @return list<string>
     */
    private function canonicalPricingProviders(array $providers): array
    {
        /** @var array<string, string> $map */
        $map = config('mediamanager.ai.pricing.providers', []);

        $canonical = [];

        foreach ($providers as $provider) {
            $id = $map[$provider] ?? (in_array($provider, $map, true) ? $provider : null);

            if ($id !== null && ! in_array($id, $canonical, true)) {
                $canonical[] = $id;
            }
        }

        return $canonical;
    }

    public function update(
        UpdateAiSettingsRequest $updateAiSettingsRequest,
        AiSettings $aiSettings,
        OpenRouterSettings $openRouterSettings,
    ): RedirectResponse {
        $validated = $updateAiSettingsRequest->validated();

        $aiSettings->setMode(AiMode::from($validated['mode']));
        $aiSettings->setModel($validated['model']);
        $aiSettings->setTitleModel($validated['title_model']);
        $aiSettings->setSoftBudgetUsd(
            isset($validated['soft_budget_usd']) ? (float) $validated['soft_budget_usd'] : null,
        );
        $aiSettings->setHardBudgetUsd(
            isset($validated['hard_budget_usd']) ? (float) $validated['hard_budget_usd'] : null,
        );
        $aiSettings->setAdvisorReasoningLevel(AiReasoningLevel::from($validated['advisor_reasoning_level']));
        $aiSettings->setChatTimeout(
            isset($validated['chat_timeout']) ? (int) $validated['chat_timeout'] : null,
        );
        $currentFailoverProvider = $aiSettings->failoverProvider();
        $newFailoverProvider = empty($validated['failover_provider']) ? null : Lab::tryFrom($validated['failover_provider']);

        $aiSettings->setFailoverProvider($newFailoverProvider);

        // A stale model id must never survive a failover provider change: it
        // was validated against the old provider's catalog, not the new
        // one's. Clear it unless the same request submits a fresh one, and
        // always clear it when failover is turned off.
        if ($newFailoverProvider === null) {
            $aiSettings->setFailoverModel(null);
        } elseif (array_key_exists('failover_model', $validated)) {
            $aiSettings->setFailoverModel($validated['failover_model']);
        } elseif ($currentFailoverProvider?->value !== $newFailoverProvider->value) {
            $aiSettings->setFailoverModel(null);
        }

        $aiSettings->setModelsDevPricingEnabled(
            array_key_exists('models_dev_pricing_enabled', $validated)
                ? (bool) $validated['models_dev_pricing_enabled']
                : null,
        );
        $aiSettings->setOpenRouterPricingEnabled(
            array_key_exists('openrouter_pricing_enabled', $validated) ? (bool) $validated['openrouter_pricing_enabled'] : null,
        );
        $aiSettings->setLiteLlmPricingEnabled(
            array_key_exists('litellm_pricing_enabled', $validated) ? (bool) $validated['litellm_pricing_enabled'] : null,
        );
        $aiSettings->setXaiPricingEnabled(
            array_key_exists('xai_pricing_enabled', $validated) ? (bool) $validated['xai_pricing_enabled'] : null,
        );
        $aiSettings->setIgnoredPricingProviders($validated['ignored_pricing_providers'] ?? []);

        // Absent means "not submitted" (leave the saved list alone); the page
        // always submits it, as an empty list when every box is unchecked.
        if (array_key_exists('auto_create_pricing_providers', $validated)) {
            $aiSettings->setAutoCreatePricingProviders($validated['auto_create_pricing_providers']);
        }

        $aiSettings->setRateLimitsEnforced(
            array_key_exists('rate_limits_enforced', $validated)
                ? (bool) $validated['rate_limits_enforced']
                : null,
        );

        $this->updateClassificationSettings($aiSettings, $validated);
        $this->updateModelProviders($aiSettings, $validated);
        $this->updateOpenRouterSettings($openRouterSettings, $validated);

        if (array_key_exists('embeddings_provider', $validated)) {
            $aiSettings->setEmbeddingsProvider($validated['embeddings_provider']);
        }

        if (array_key_exists('embeddings_model', $validated)) {
            $aiSettings->setEmbeddingsModel($validated['embeddings_model']);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('AI settings updated.')]);

        return to_route('admin.ai-settings.index');
    }

    public function reembed(): RedirectResponse
    {
        if (! Cache::add(ReembedLibrary::RUNNING_CACHE_KEY, true, ReembedLibrary::RUNNING_CACHE_TTL)) {
            Inertia::flash('toast', ['type' => 'info', 'message' => __('A library re-embed is already running.')]);

            return to_route('admin.ai-settings.index');
        }

        dispatch(new ReembedLibrary);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Library re-embed queued. Semantic search improves as it completes.')]);

        return to_route('admin.ai-settings.index');
    }

    /**
     * Persist the classification, reranking and sub-agent fields that were
     * submitted. An absent field leaves its saved setting untouched; a blank
     * model (normalized to null by the request) clears it back to the default.
     *
     * @param  array<string, mixed>  $validated
     */
    private function updateClassificationSettings(AiSettings $aiSettings, array $validated): void
    {
        if (array_key_exists('classification_provider', $validated)) {
            $aiSettings->setClassificationProvider($validated['classification_provider']);
        }

        if (array_key_exists('classification_model', $validated)) {
            $aiSettings->setClassificationModel($validated['classification_model']);
        }

        if (array_key_exists('decision_gate_enabled', $validated)) {
            $aiSettings->setDecisionGateEnabled((bool) $validated['decision_gate_enabled']);
        }

        if (array_key_exists('decision_gate_threshold', $validated)) {
            $aiSettings->setDecisionGateThreshold((float) $validated['decision_gate_threshold']);
        }

        if (array_key_exists('subtitle_triage_enabled', $validated)) {
            $aiSettings->setSubtitleTriageEnabled((bool) $validated['subtitle_triage_enabled']);
        }

        if (array_key_exists('subtitle_triage_threshold', $validated)) {
            $aiSettings->setSubtitleTriageThreshold((float) $validated['subtitle_triage_threshold']);
        }

        if (array_key_exists('chat_routing_enabled', $validated)) {
            $aiSettings->setChatRoutingEnabled((bool) $validated['chat_routing_enabled']);
        }

        if (array_key_exists('reranking_provider', $validated)) {
            $aiSettings->setRerankingProvider($validated['reranking_provider']);
        }

        if (array_key_exists('reranking_model', $validated)) {
            $aiSettings->setRerankingModel($validated['reranking_model']);
        }

        if (array_key_exists('sub_agent_model', $validated)) {
            $aiSettings->setSubAgentModel($validated['sub_agent_model']);
        }

        if (array_key_exists('price_updater_model', $validated)) {
            $aiSettings->setPriceUpdaterModel($validated['price_updater_model']);
        }
    }

    /**
     * Persist the provider half of each submitted model selection. An absent
     * field leaves its saved provider untouched; a blank one clears it.
     *
     * @param  array<string, mixed>  $validated
     */
    private function updateModelProviders(AiSettings $aiSettings, array $validated): void
    {
        if (array_key_exists('model_provider', $validated)) {
            $aiSettings->setModelProvider($validated['model_provider']);
        }

        if (array_key_exists('title_model_provider', $validated)) {
            $aiSettings->setTitleModelProvider($validated['title_model_provider']);
        }

        if (array_key_exists('sub_agent_model_provider', $validated)) {
            $aiSettings->setSubAgentModelProvider($validated['sub_agent_model_provider']);
        }

        if (array_key_exists('price_updater_model_provider', $validated)) {
            $aiSettings->setPriceUpdaterModelProvider($validated['price_updater_model_provider']);
        }
    }

    /**
     * Persist the submitted OpenRouter routing preferences. Order and ignore
     * arrive as comma-separated slugs.
     *
     * @param  array<string, mixed>  $validated
     */
    private function updateOpenRouterSettings(OpenRouterSettings $openRouterSettings, array $validated): void
    {
        if (array_key_exists('openrouter_sort', $validated)) {
            if ($validated['openrouter_sort'] === 'default') {
                $openRouterSettings->useDefaultSort();
            } else {
                $openRouterSettings->setSort(OpenRouterSort::tryFrom((string) $validated['openrouter_sort']));
            }
        }

        if (array_key_exists('openrouter_deny_data_collection', $validated)) {
            $openRouterSettings->setDenyDataCollection((bool) $validated['openrouter_deny_data_collection']);
        }

        if (array_key_exists('openrouter_allow_fallbacks', $validated)) {
            $openRouterSettings->setAllowFallbacks((bool) $validated['openrouter_allow_fallbacks']);
        }

        if (array_key_exists('openrouter_order', $validated)) {
            $openRouterSettings->setOrder(explode(',', (string) $validated['openrouter_order']));
        }

        if (array_key_exists('openrouter_ignore', $validated)) {
            $openRouterSettings->setIgnore(explode(',', (string) $validated['openrouter_ignore']));
        }
    }
}
