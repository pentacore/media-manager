<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateDecisionAgentSettingsRequest;
use App\Models\AiModelPrice;
use App\Settings\DecisionAgentSettings;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class DecisionAgentSettingsController extends Controller
{
    public function index(DecisionAgentSettings $decisionAgentSettings): Response
    {
        return Inertia::render('Admin/DecisionAgent/Index', [
            'settings' => [
                'enabled' => $decisionAgentSettings->enabled(),
                'model' => $decisionAgentSettings->model(),
                'event_allowlist' => $decisionAgentSettings->eventAllowlist(),
                'allow_manual_import' => $decisionAgentSettings->allowManualImport(),
                'notify_on_suggest' => $decisionAgentSettings->notifyOnSuggest(),
                'notify_on_act' => $decisionAgentSettings->notifyOnAct(),
                'max_actions_per_run' => $decisionAgentSettings->maxActionsPerRun(),
            ],
            'models' => $this->modelsByConfiguredProvider(),
            'eventCatalog' => DecisionAgentSettings::eventCatalog(),
        ]);
    }

    public function update(UpdateDecisionAgentSettingsRequest $updateDecisionAgentSettingsRequest, DecisionAgentSettings $decisionAgentSettings): RedirectResponse
    {
        $validated = $updateDecisionAgentSettingsRequest->validated();

        $decisionAgentSettings->setEnabled((bool) $validated['enabled']);
        $decisionAgentSettings->setModel($validated['model']);
        $decisionAgentSettings->setEventAllowlist($validated['event_allowlist'] ?? []);
        $decisionAgentSettings->setAllowManualImport((bool) $validated['allow_manual_import']);
        $decisionAgentSettings->setNotifyOnSuggest((bool) $validated['notify_on_suggest']);
        $decisionAgentSettings->setNotifyOnAct((bool) $validated['notify_on_act']);
        $decisionAgentSettings->setMaxActionsPerRun((int) $validated['max_actions_per_run']);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Decision agent settings updated.')]);

        return to_route('admin.decision-agent.index');
    }

    /**
     * Models grouped by configured provider — mirrors the chat AI settings page
     * so the agent can run on any model the install is set up for.
     *
     * @return array<string, array<int, string>>
     */
    private function modelsByConfiguredProvider(): array
    {
        $configured = collect(config('ai.providers', []))
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
}
