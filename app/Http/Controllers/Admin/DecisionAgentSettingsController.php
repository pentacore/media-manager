<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Ai\ModelCatalog;
use App\Enums\AiReasoningLevel;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateDecisionAgentSettingsRequest;
use App\Settings\DecisionAgentSettings;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class DecisionAgentSettingsController extends Controller
{
    public function index(DecisionAgentSettings $decisionAgentSettings, ModelCatalog $modelCatalog): Response
    {
        return Inertia::render('Admin/DecisionAgent/Index', [
            'settings' => [
                'enabled' => $decisionAgentSettings->enabled(),
                'model' => $decisionAgentSettings->model(),
                'model_provider' => $decisionAgentSettings->selection()->provider,
                'event_allowlist' => $decisionAgentSettings->eventAllowlist(),
                'allow_manual_import' => $decisionAgentSettings->allowManualImport(),
                'notify_on_suggest' => $decisionAgentSettings->notifyOnSuggest(),
                'notify_on_act' => $decisionAgentSettings->notifyOnAct(),
                'max_actions_per_run' => $decisionAgentSettings->maxActionsPerRun(),
                'reasoning_level' => $decisionAgentSettings->reasoning(),
            ],
            'models' => $modelCatalog->modelsByConfiguredProvider(),
            'eventCatalog' => DecisionAgentSettings::eventCatalog(),
            'reasoningLevels' => AiReasoningLevel::mapForSelect(labelKey: 'label'),
        ]);
    }

    public function update(UpdateDecisionAgentSettingsRequest $updateDecisionAgentSettingsRequest, DecisionAgentSettings $decisionAgentSettings): RedirectResponse
    {
        $validated = $updateDecisionAgentSettingsRequest->validated();

        $decisionAgentSettings->setEnabled((bool) $validated['enabled']);
        $decisionAgentSettings->setModel($validated['model']);

        if (array_key_exists('model_provider', $validated)) {
            $decisionAgentSettings->setModelProvider($validated['model_provider']);
        }

        $decisionAgentSettings->setEventAllowlist($validated['event_allowlist'] ?? []);
        $decisionAgentSettings->setAllowManualImport((bool) $validated['allow_manual_import']);
        $decisionAgentSettings->setNotifyOnSuggest((bool) $validated['notify_on_suggest']);
        $decisionAgentSettings->setNotifyOnAct((bool) $validated['notify_on_act']);
        $decisionAgentSettings->setMaxActionsPerRun((int) $validated['max_actions_per_run']);
        $decisionAgentSettings->setReasoning(AiReasoningLevel::from($validated['reasoning_level']));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Decision agent settings updated.')]);

        return to_route('admin.decision-agent.index');
    }
}
