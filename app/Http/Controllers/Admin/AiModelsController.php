<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Ai\ModelCatalog;
use App\Ai\ReasoningOptions;
use App\Ai\ResolvedSelection;
use App\Ai\TaskModelResolver;
use App\Enums\AiReasoningLevel;
use App\Enums\AiTask;
use App\Enums\SettingsGroup;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateAiModelsRequest;
use App\Models\AiModelPrice;
use App\Models\AiTaskModel;
use App\Services\AiBudget\UnpricedModelDetector;
use App\Services\Audit\AuditLogger;
use App\Settings\DecisionAgentSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class AiModelsController extends Controller
{
    public function index(
        TaskModelResolver $taskModelResolver,
        ModelCatalog $modelCatalog,
        DecisionAgentSettings $decisionAgentSettings,
        UnpricedModelDetector $unpricedModelDetector,
    ): Response {
        $allowlist = $decisionAgentSettings->eventAllowlist();

        $tasks = [];

        foreach (AiTask::cases() as $aiTask) {
            if ($aiTask === AiTask::Failover) {
                continue;
            }

            $row = $taskModelResolver->row($aiTask);

            $tasks[] = [
                'task' => $aiTask->value,
                'label' => $aiTask->label(),
                'inherits_chat' => $aiTask->inheritsChatModel(),
                'has_reasoning' => $aiTask->hasReasoning(),
                'allow_auto' => $aiTask === AiTask::Title,
                'provider' => $row?->provider,
                'model' => $row?->model,
                'reasoning' => $row?->reasoning?->value,
                'resolved' => $this->summary($taskModelResolver->resolve($aiTask)),
            ];
        }

        $eventOverrides = $taskModelResolver->rows()
            ->filter(fn (AiTaskModel $aiTaskModel): bool => $aiTaskModel->task === AiTask::Decision && $aiTaskModel->scope !== AiTaskModel::DEFAULT_SCOPE)
            ->sortBy('scope')
            ->map(fn (AiTaskModel $aiTaskModel): array => [
                'event_key' => $aiTaskModel->scope,
                'enabled' => in_array($aiTaskModel->scope, $allowlist, true),
                'provider' => $aiTaskModel->provider,
                'model' => $aiTaskModel->model,
                'reasoning' => $aiTaskModel->reasoning?->value,
                'resolved' => $this->summary($taskModelResolver->resolve(AiTask::Decision, $aiTaskModel->scope)),
            ])
            ->values()
            ->all();

        $failover = $taskModelResolver->failover();

        return Inertia::render('Admin/AiModels/Index', [
            'tasks' => $tasks,
            'failover' => ['provider' => $failover['provider'] ?? null, 'model' => $failover['model'] ?? null],
            'eventOverrides' => $eventOverrides,
            'allowlistedEvents' => $allowlist,
            'models' => $modelCatalog->modelsByConfiguredProvider(),
            'providers' => $modelCatalog->textProviders(),
            'reasoningLevels' => AiReasoningLevel::mapForSelect(labelKey: 'label'),
            'modelCapabilities' => $modelCatalog->reasoningCapabilities(),
            'reasoningProviders' => array_values(array_filter($modelCatalog->textProviders(), ReasoningOptions::supportsProvider(...))),
            'unpricedModels' => $unpricedModelDetector->forHardCap(),
        ]);
    }

    public function update(UpdateAiModelsRequest $updateAiModelsRequest, AuditLogger $auditLogger): RedirectResponse
    {
        $validated = $updateAiModelsRequest->validated();

        DB::transaction(function () use ($validated, $auditLogger): void {
            $before = $this->snapshot();

            foreach ($validated['tasks'] as $task => $selection) {
                $this->store(AiTask::from($task), AiTaskModel::DEFAULT_SCOPE, $selection);
            }

            $this->store(AiTask::Failover, AiTaskModel::DEFAULT_SCOPE, [
                'provider' => $validated['failover']['provider'] ?? null,
                // A model only makes sense next to a provider.
                'model' => filled($validated['failover']['provider'] ?? null) ? ($validated['failover']['model'] ?? null) : null,
                'reasoning' => null,
            ]);

            $keptScopes = array_column($validated['event_overrides'], 'event_key');

            AiTaskModel::query()
                ->forTask(AiTask::Decision)
                ->where('scope', '!=', AiTaskModel::DEFAULT_SCOPE)
                ->whereNotIn('scope', $keptScopes)
                ->get()
                ->each->delete();

            foreach ($validated['event_overrides'] as $override) {
                $this->store(AiTask::Decision, $override['event_key'], $override);
            }

            $auditLogger->settingsUpdated(SettingsGroup::AiModels, $before, $this->snapshot());
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('AI models updated.')]);

        return to_route('admin.ai-models.index');
    }

    /**
     * Upsert one row, or delete it when every field inherits.
     *
     * @param  array{provider?: string|null, model?: string|null, reasoning?: string|null}  $selection
     */
    private function store(AiTask $aiTask, string $scope, array $selection): void
    {
        $attributes = [
            'provider' => filled($selection['model'] ?? null) || $aiTask === AiTask::Failover ? ($selection['provider'] ?? null) : null,
            'model' => $selection['model'] ?? null,
            'reasoning' => $selection['reasoning'] ?? null,
        ];

        $existing = AiTaskModel::query()->forTask($aiTask)->where('scope', $scope)->first();

        if (array_filter($attributes, filled(...)) === []) {
            $existing?->delete();

            return;
        }

        ($existing ?? new AiTaskModel(['task' => $aiTask, 'scope' => $scope]))->fill($attributes)->save();
    }

    /**
     * @return array<string, string>
     */
    private function snapshot(): array
    {
        return AiTaskModel::query()
            ->orderBy('task')->orderBy('scope')
            ->get()
            ->mapWithKeys(fn (AiTaskModel $aiTaskModel): array => [
                sprintf('%s:%s', $aiTaskModel->task->value, $aiTaskModel->scope) => sprintf(
                    '%s/%s · %s',
                    $aiTaskModel->provider ?? 'inherit',
                    $aiTaskModel->model ?? 'inherit',
                    $aiTaskModel->reasoning->value ?? 'inherit',
                ),
            ])
            ->all();
    }

    /**
     * @return array{provider: string, model: string, reasoning: string, reasoning_label: string, reasoning_hint: string|null}
     */
    private function summary(ResolvedSelection $resolvedSelection): array
    {
        $supported = AiModelPrice::query()
            ->where('provider', $resolvedSelection->provider)
            ->where('model', $resolvedSelection->model)
            ->value('supports_reasoning');

        return [
            'provider' => $resolvedSelection->provider,
            'model' => $resolvedSelection->model,
            'reasoning' => $resolvedSelection->reasoning->value,
            'reasoning_label' => $resolvedSelection->reasoning->label(),
            'reasoning_hint' => match (true) {
                ! ReasoningOptions::supportsProvider($resolvedSelection->provider) => __('Not supported by this provider'),
                $supported === false => __("Model doesn't reason"),
                default => null,
            },
        ];
    }
}
