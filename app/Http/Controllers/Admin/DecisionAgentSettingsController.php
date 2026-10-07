<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\SettingsGroup;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateDecisionAgentSettingsRequest;
use App\Services\Audit\AuditLogger;
use App\Services\Audit\SettingsSnapshot;
use App\Settings\DecisionAgentSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class DecisionAgentSettingsController extends Controller
{
    public function index(DecisionAgentSettings $decisionAgentSettings): Response
    {
        return Inertia::render('Admin/DecisionAgent/Index', [
            'settings' => [
                'enabled' => $decisionAgentSettings->enabled(),
                'event_allowlist' => $decisionAgentSettings->eventAllowlist(),
                'allow_manual_import' => $decisionAgentSettings->allowManualImport(),
                'notify_on_suggest' => $decisionAgentSettings->notifyOnSuggest(),
                'notify_on_act' => $decisionAgentSettings->notifyOnAct(),
                'max_actions_per_run' => $decisionAgentSettings->maxActionsPerRun(),
            ],
            'eventCatalog' => DecisionAgentSettings::eventCatalog(),
        ]);
    }

    public function update(
        UpdateDecisionAgentSettingsRequest $updateDecisionAgentSettingsRequest,
        DecisionAgentSettings $decisionAgentSettings,
        SettingsSnapshot $settingsSnapshot,
        AuditLogger $auditLogger,
    ): RedirectResponse {
        $before = $settingsSnapshot->capture(SettingsGroup::DecisionAgent);
        $validated = $updateDecisionAgentSettingsRequest->validated();

        DB::transaction(function () use ($decisionAgentSettings, $settingsSnapshot, $auditLogger, $before, $validated): void {
            $decisionAgentSettings->setEnabled((bool) $validated['enabled']);
            $decisionAgentSettings->setEventAllowlist($validated['event_allowlist'] ?? []);
            $decisionAgentSettings->setAllowManualImport((bool) $validated['allow_manual_import']);
            $decisionAgentSettings->setNotifyOnSuggest((bool) $validated['notify_on_suggest']);
            $decisionAgentSettings->setNotifyOnAct((bool) $validated['notify_on_act']);
            $decisionAgentSettings->setMaxActionsPerRun((int) $validated['max_actions_per_run']);

            $auditLogger->settingsUpdated(SettingsGroup::DecisionAgent, $before, $settingsSnapshot->capture(SettingsGroup::DecisionAgent));
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Decision agent settings updated.')]);

        return to_route('admin.decision-agent.index');
    }
}
