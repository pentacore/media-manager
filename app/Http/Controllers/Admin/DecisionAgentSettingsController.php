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
    public function index(DecisionAgentSettings $settings): Response
    {
        return Inertia::render('Admin/DecisionAgent/Index', [
            'settings' => [
                'enabled' => $settings->enabled(),
                'model' => $settings->model(),
                'event_allowlist' => $settings->eventAllowlist(),
                'allow_manual_import' => $settings->allowManualImport(),
                'notify_on_suggest' => $settings->notifyOnSuggest(),
                'notify_on_act' => $settings->notifyOnAct(),
                'max_actions_per_run' => $settings->maxActionsPerRun(),
            ],
            'models' => $this->modelsByConfiguredProvider(),
            'eventCatalog' => DecisionAgentSettings::eventCatalog(),
        ]);
    }

    public function update(UpdateDecisionAgentSettingsRequest $request, DecisionAgentSettings $settings): RedirectResponse
    {
        $validated = $request->validated();

        $settings->setEnabled((bool) $validated['enabled']);
        $settings->setModel($validated['model']);
        $settings->setEventAllowlist($validated['event_allowlist'] ?? []);
        $settings->setAllowManualImport((bool) $validated['allow_manual_import']);
        $settings->setNotifyOnSuggest((bool) $validated['notify_on_suggest']);
        $settings->setNotifyOnAct((bool) $validated['notify_on_act']);
        $settings->setMaxActionsPerRun((int) $validated['max_actions_per_run']);

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
