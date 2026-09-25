<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Ai\ProviderCapabilities;
use App\Enums\AiMode;
use App\Enums\AiReasoningLevel;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateAiSettingsRequest;
use App\Models\AiModelPrice;
use App\Services\AiBudget\AiBudgetGuard;
use App\Settings\AiSettings;
use Illuminate\Http\RedirectResponse;
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
                'sub_agent_model' => $aiSettings->rawSubAgentModel(),
            ],
            'budget' => [
                'spend' => round($aiBudgetGuard->currentMonthSpend(), 4),
                'soft' => $aiSettings->softBudgetUsd(),
                'hard' => $aiSettings->hardBudgetUsd(),
                'soft_notified_at' => $aiSettings->softBudgetNotifiedAt(),
            ],
            'modes' => AiMode::mapForSelect(labelKey: 'label'),
            'models' => $this->modelsByConfiguredProvider(),
            'reasoningLevels' => AiReasoningLevel::mapForSelect(labelKey: 'label'),
            'failoverProviders' => [
                ['value' => 'none', 'label' => 'None'],
                ['value' => Lab::Anthropic->value, 'label' => 'Anthropic'],
                ['value' => Lab::OpenAI->value, 'label' => 'OpenAI'],
                ['value' => Lab::Gemini->value, 'label' => 'Gemini'],
                ['value' => Lab::Groq->value, 'label' => 'Groq'],
                ['value' => Lab::Mistral->value, 'label' => 'Mistral'],
            ],
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
            'providerKeys' => collect(['openrouter', 'typesafe', 'cohere', 'jina'])
                ->mapWithKeys(fn (string $provider): array => [$provider => filled(config(sprintf('ai.providers.%s.key', $provider)))])
                ->all(),
            'advancedTools' => [
                'tool_search' => $providerCapabilities->everyProviderSupports(SupportsToolSearch::class),
                'code_execution' => $providerCapabilities->everyProviderSupports(SupportsCodeExecution::class),
            ],
        ]);
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function modelsByConfiguredProvider(): array
    {
        $configured = collect(config('ai.providers', []))
            // Ollama runs locally and ignores the key; treat it as always configured when listed.
            ->filter(fn (array $cfg, string $name): bool => $name === 'ollama' || filled($cfg['key'] ?? null))
            ->keys()
            ->all();

        if ($configured === []) {
            return [];
        }

        return AiModelPrice::query()
            ->whereIn('provider', $configured)
            ->orderBy('provider')
            ->orderBy('model')
            ->get(['provider', 'model'])
            ->groupBy('provider')
            ->map(fn ($rows): array => $rows->pluck('model')->all())
            ->all();
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

        return collect(array_values($map))
            ->unique()
            ->values()
            ->map(fn (string $provider): array => [
                'value' => $provider,
                'label' => $labels[$provider] ?? ucfirst($provider),
            ])
            ->all();
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
        $aiSettings->setFailoverProvider(
            empty($validated['failover_provider']) ? null : Lab::tryFrom($validated['failover_provider']),
        );
        $aiSettings->setModelsDevPricingEnabled(
            array_key_exists('models_dev_pricing_enabled', $validated)
                ? (bool) $validated['models_dev_pricing_enabled']
                : null,
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

        Inertia::flash('toast', ['type' => 'success', 'message' => __('AI settings updated.')]);

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
    }
}
