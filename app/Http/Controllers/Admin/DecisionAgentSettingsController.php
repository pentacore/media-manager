<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Ai\Classification\Classifier;
use App\Enums\SecondOpinionEngine;
use App\Enums\SecondOpinionMode;
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
    public function index(DecisionAgentSettings $decisionAgentSettings, Classifier $classifier): Response
    {
        return Inertia::render('Admin/DecisionAgent/Index', [
            'settings' => [
                'enabled' => $decisionAgentSettings->enabled(),
                'event_allowlist' => $decisionAgentSettings->eventAllowlist(),
                'allow_manual_import' => $decisionAgentSettings->allowManualImport(),
                'notify_on_suggest' => $decisionAgentSettings->notifyOnSuggest(),
                'notify_on_act' => $decisionAgentSettings->notifyOnAct(),
                'max_actions_per_run' => $decisionAgentSettings->maxActionsPerRun(),
                'second_opinion_engine' => $decisionAgentSettings->secondOpinionEngine()->value,
                'second_opinion_jev_threshold' => $decisionAgentSettings->secondOpinionJevThreshold(),
                'second_opinion_event_overrides' => $this->eventOverrideRows($decisionAgentSettings),
            ],
            'eventCatalog' => DecisionAgentSettings::eventCatalog(),
            'secondOpinionEngines' => array_map(
                static fn (SecondOpinionEngine $secondOpinionEngine): array => ['value' => $secondOpinionEngine->value, 'label' => $secondOpinionEngine->label()],
                SecondOpinionEngine::cases(),
            ),
            'secondOpinionModes' => array_map(
                static fn (SecondOpinionMode $secondOpinionMode): array => ['value' => $secondOpinionMode->value, 'label' => $secondOpinionMode->label()],
                SecondOpinionMode::cases(),
            ),
            'classifierConfigured' => $classifier->isConfigured(),
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
            $decisionAgentSettings->setSecondOpinionEngine(SecondOpinionEngine::from((string) $validated['second_opinion_engine']));
            $decisionAgentSettings->setSecondOpinionJevThreshold((float) $validated['second_opinion_jev_threshold']);

            if (array_key_exists('second_opinion_event_overrides', $validated)) {
                $decisionAgentSettings->setSecondOpinionEventOverrides($this->eventOverridesFromInput($validated['second_opinion_event_overrides']));
            }

            $auditLogger->settingsUpdated(SettingsGroup::DecisionAgent, $before, $settingsSnapshot->capture(SettingsGroup::DecisionAgent));
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Decision agent settings updated.')]);

        return to_route('admin.decision-agent.index');
    }

    /**
     * @return list<array{event_key: string, mode: string|null, engine: string|null, jev_threshold: float|null}>
     */
    private function eventOverrideRows(DecisionAgentSettings $decisionAgentSettings): array
    {
        $rows = [];

        foreach ($decisionAgentSettings->secondOpinionEventOverrides() as $eventKey => $override) {
            $rows[] = [
                'event_key' => $eventKey,
                'mode' => $override['mode']?->value,
                'engine' => $override['engine']?->value,
                'jev_threshold' => $override['jev_threshold'],
            ];
        }

        return $rows;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, array{mode: ?SecondOpinionMode, engine: ?SecondOpinionEngine, jev_threshold: ?float}>
     */
    private function eventOverridesFromInput(array $rows): array
    {
        $overrides = [];

        foreach ($rows as $row) {
            $mode = $row['mode'] ?? null;
            $engine = $row['engine'] ?? null;
            $threshold = $row['jev_threshold'] ?? null;

            $overrides[(string) $row['event_key']] = [
                'mode' => is_string($mode) ? SecondOpinionMode::tryFrom($mode) : null,
                'engine' => is_string($engine) ? SecondOpinionEngine::tryFrom($engine) : null,
                'jev_threshold' => $threshold === null ? null : (float) $threshold,
            ];
        }

        return $overrides;
    }
}
