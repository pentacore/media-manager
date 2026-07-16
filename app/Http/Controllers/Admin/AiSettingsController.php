<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\AiMode;
use App\Enums\AiReasoningLevel;
use App\Enums\SeasonPackPolicy;
use App\Enums\SubtitleRuleStrength;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateAiSettingsRequest;
use App\Models\AiModelPrice;
use App\Services\AiBudget\AiBudgetGuard;
use App\Services\MediaReplacement\SonarrRootFolderCatalog;
use App\Settings\AiSettings;
use App\Settings\MediaReplacementSettings;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Ai\Enums\Lab;

class AiSettingsController extends Controller
{
    public function index(
        AiSettings $aiSettings,
        AiBudgetGuard $aiBudgetGuard,
        MediaReplacementSettings $mediaReplacementSettings,
        SonarrRootFolderCatalog $sonarrRootFolderCatalog,
    ): Response {
        return Inertia::render('Admin/AiSettings/Index', [
            'settings' => [
                'mode' => $aiSettings->mode()->value,
                'model' => $aiSettings->model(),
                'title_model' => $aiSettings->rawTitleModel(),
                'soft_budget_usd' => $aiSettings->softBudgetUsd(),
                'hard_budget_usd' => $aiSettings->hardBudgetUsd(),
                'advisor_reasoning_level' => $aiSettings->advisorReasoningLevel(),
                'failover_provider' => $aiSettings->failoverProvider()?->value ?? 'none',
                'media_replacement' => $mediaReplacementSettings->configuration(),
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
            'seasonPackPolicies' => SeasonPackPolicy::mapForSelect(labelKey: 'label'),
            'subtitleRuleStrengths' => SubtitleRuleStrength::mapForSelect(labelKey: 'label'),
            'conditionFields' => [
                ['value' => 'release_group', 'label' => 'Release group'],
                ['value' => 'subgroup', 'label' => 'Subgroup'],
                ['value' => 'title', 'label' => 'Title token/phrase'],
                ['value' => 'custom_format', 'label' => 'Custom format'],
            ],
            'sonarrRootFolders' => $sonarrRootFolderCatalog->all(),
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

    public function update(
        UpdateAiSettingsRequest $updateAiSettingsRequest,
        AiSettings $aiSettings,
        MediaReplacementSettings $mediaReplacementSettings,
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
        $aiSettings->setFailoverProvider(
            empty($validated['failover_provider']) ? null : Lab::tryFrom($validated['failover_provider']),
        );
        $mediaReplacementSettings->setConfiguration($validated['media_replacement']);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('AI settings updated.')]);

        return to_route('admin.ai-settings.index');
    }
}
