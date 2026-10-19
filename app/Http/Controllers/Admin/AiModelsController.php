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
use App\Models\BenchmarkRun;
use App\Services\AiBudget\UnpricedModelDetector;
use App\Services\AiUsage\PoolHeadroom;
use App\Services\AiUsage\PoolHeadroomFigures;
use App\Services\AiUsage\PoolHeadroomUnavailable;
use App\Services\Audit\AuditLogger;
use App\Settings\DecisionAgentSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class AiModelsController extends Controller
{
    public function index(
        Request $request,
        TaskModelResolver $taskModelResolver,
        ModelCatalog $modelCatalog,
        DecisionAgentSettings $decisionAgentSettings,
        UnpricedModelDetector $unpricedModelDetector,
        PoolHeadroom $poolHeadroom,
    ): Response|RedirectResponse {
        $prefill = null;

        if ($request->has('benchmark_run')) {
            $prefill = $this->benchmarkPrefill($request, $modelCatalog);

            if ($prefill === null) {
                Inertia::flash('toast', ['type' => 'error', 'message' => __('That benchmark candidate is no longer available.')]);

                return to_route('admin.ai-models.index');
            }

            if (! in_array($prefill['provider'], $modelCatalog->textProviders(), true)) {
                Inertia::flash('toast', ['type' => 'error', 'message' => __("That benchmark candidate's provider is no longer configured.")]);

                return to_route('admin.ai-models.index');
            }
        }

        $allowlist = $decisionAgentSettings->eventAllowlist();

        $modelCapabilities = $modelCatalog->reasoningCapabilities();
        $aiTasks = array_filter(AiTask::cases(), static fn (AiTask $aiTask): bool => $aiTask !== AiTask::Failover);
        $resolvedByTask = [];

        foreach ($aiTasks as $aiTask) {
            $resolvedByTask[$aiTask->value] = $taskModelResolver->resolve($aiTask);
        }

        $tasks = [];

        foreach ($aiTasks as $aiTask) {
            $tasks[] = [
                'task' => $aiTask->value,
                'label' => $aiTask->label(),
                'inherits_chat' => $aiTask->inheritsChatModel(),
                'has_reasoning' => $aiTask->hasReasoning(),
                'allow_auto' => $aiTask === AiTask::Title,
                'allow_tiers' => $aiTask->allowsTiers(),
                'tiers' => $this->tierRows($taskModelResolver->tiers($aiTask)),
                'resolved' => $this->summary($resolvedByTask[$aiTask->value], $modelCapabilities),
                'inherited' => $this->summary($taskModelResolver->inheritedSelection($aiTask), $modelCapabilities),
                'warning' => $this->taskWarning($aiTask, $resolvedByTask),
            ];
        }

        $eventOverrides = $taskModelResolver->rows()
            ->filter(fn (AiTaskModel $aiTaskModel): bool => $aiTaskModel->task === AiTask::Decision && $aiTaskModel->scope !== AiTaskModel::DEFAULT_SCOPE)
            ->pluck('scope')
            ->unique()
            ->sort()
            ->map(fn (string $scope): array => [
                'event_key' => $scope,
                'enabled' => in_array($scope, $allowlist, true),
                'tiers' => $this->tierRows($taskModelResolver->tiers(AiTask::Decision, $scope)),
                'resolved' => $this->summary($taskModelResolver->resolve(AiTask::Decision, $scope), $modelCapabilities),
                'inherited' => $this->summary($taskModelResolver->inheritedSelection(AiTask::Decision, $scope), $modelCapabilities),
            ])
            ->values()
            ->all();

        $failover = $taskModelResolver->failover();

        return Inertia::render('Admin/AiModels/Index', [
            'tasks' => $tasks,
            'failover' => ['provider' => $failover['provider'] ?? null, 'model' => $failover['model'] ?? null],
            'eventOverrides' => $eventOverrides,
            'modelPools' => $this->modelPools($poolHeadroom),
            'allowlistedEvents' => $allowlist,
            'models' => $modelCatalog->modelsByConfiguredProvider(),
            'providers' => $modelCatalog->textProviders(),
            'reasoningLevels' => AiReasoningLevel::mapForSelect(labelKey: 'label'),
            'modelCapabilities' => $modelCapabilities,
            'reasoningProviders' => $modelCatalog->reasoningProviders(),
            'unpricedModels' => $unpricedModelDetector->forHardCap(),
            'prefill' => $prefill,
        ]);
    }

    /**
     * A caution shown under a task's live selection, or null.
     *
     * @param  array<string, ResolvedSelection>  $resolvedByTask  each task's live selection, keyed by task value
     */
    private function taskWarning(AiTask $aiTask, array $resolvedByTask): ?string
    {
        if ($aiTask !== AiTask::DecisionVerifier) {
            return null;
        }

        $resolvedSelection = $resolvedByTask[AiTask::DecisionVerifier->value];
        $decision = $resolvedByTask[AiTask::Decision->value];

        return $resolvedSelection->provider === $decision->provider && $resolvedSelection->model === $decision->model
            ? __('Runs on the same model as the Decision agent, so its second opinion is less independent.')
            : null;
    }

    /**
     * The benchmark candidate a "Use this" link points at, or null when the
     * run, candidate or task no longer exists. The reasoning level is dropped
     * when the catalog says the model takes none.
     *
     * @return array{task: string, provider: string, model: string, reasoning: string|null, run_id: int, run_name: string}|null
     */
    private function benchmarkPrefill(Request $request, ModelCatalog $modelCatalog): ?array
    {
        $runId = $request->query('benchmark_run');
        $candidateIndex = $request->query('candidate');
        $taskValue = $request->query('task');

        if (! is_string($runId) || ! ctype_digit($runId) || ! is_string($candidateIndex) || ! ctype_digit($candidateIndex) || ! is_string($taskValue)) {
            return null;
        }

        $aiTask = AiTask::tryFrom($taskValue);
        $benchmarkRun = BenchmarkRun::query()->find((int) $runId);

        if ($aiTask === null || $aiTask === AiTask::Failover || ! $benchmarkRun instanceof BenchmarkRun) {
            return null;
        }

        $candidate = $benchmarkRun->candidates[(int) $candidateIndex] ?? null;

        if (! is_array($candidate) || ! is_string($candidate['provider'] ?? null) || ! is_string($candidate['model'] ?? null)) {
            return null;
        }

        if (! $benchmarkRun->results()->where('case_task', $aiTask->value)->exists()) {
            return null;
        }

        $reasoning = is_string($candidate['reasoning'] ?? null) ? AiReasoningLevel::tryFrom($candidate['reasoning']) : null;

        $supportsReasoning = $modelCatalog->reasoningCapabilities()[sprintf('%s|%s', $candidate['provider'], $candidate['model'])]['supports_reasoning'] ?? null;

        return [
            'task' => $aiTask->value,
            'provider' => $candidate['provider'],
            'model' => $candidate['model'],
            'reasoning' => $aiTask->hasReasoning() && $supportsReasoning !== false ? $reasoning?->value : null,
            'run_id' => $benchmarkRun->id,
            'run_name' => $benchmarkRun->name,
        ];
    }

    public function update(UpdateAiModelsRequest $updateAiModelsRequest, AuditLogger $auditLogger): RedirectResponse
    {
        $validated = $updateAiModelsRequest->validated();

        DB::transaction(function () use ($validated, $auditLogger): void {
            $before = $this->snapshot();

            foreach ($validated['tasks'] as $task => $selection) {
                $this->storeTiers(AiTask::from($task), AiTaskModel::DEFAULT_SCOPE, $selection['tiers']);
            }

            $this->storeFailover($validated['failover']);

            $keptScopes = array_column($validated['event_overrides'], 'event_key');

            AiTaskModel::query()
                ->forTask(AiTask::Decision)
                ->where('scope', '!=', AiTaskModel::DEFAULT_SCOPE)
                ->whereNotIn('scope', $keptScopes)
                ->get()
                ->each->delete();

            foreach ($validated['event_overrides'] as $override) {
                $this->storeTiers(AiTask::Decision, $override['event_key'], $override['tiers']);
            }

            $auditLogger->settingsUpdated(SettingsGroup::AiModels, $before, $this->snapshot());
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('AI models updated.')]);

        return to_route('admin.ai-models.index');
    }

    /**
     * A scope's tiers as the editor shows them; a scope without rows is one
     * all-inherit tier.
     *
     * @param  Collection<int, AiTaskModel>  $tiers
     * @return list<array{provider: string|null, model: string|null, reasoning: string|null, min_pool_percent: int|null, min_pool_tokens: int|null}>
     */
    private function tierRows(Collection $tiers): array
    {
        if ($tiers->isEmpty()) {
            return [['provider' => null, 'model' => null, 'reasoning' => null, 'min_pool_percent' => null, 'min_pool_tokens' => null]];
        }

        return $tiers->map(fn (AiTaskModel $aiTaskModel): array => [
            'provider' => $this->savedProvider($aiTaskModel),
            'model' => $aiTaskModel->model,
            'reasoning' => $aiTaskModel->reasoning?->value,
            'min_pool_percent' => $aiTaskModel->min_pool_percent,
            'min_pool_tokens' => $aiTaskModel->min_pool_tokens,
        ])->values()->all();
    }

    /**
     * Live headroom per priced model linked to a pool that caps something,
     * keyed `provider|model`, so the tier editor can show it for any pick.
     *
     * @return array<string, array{name: string, percent_left: float, tokens_left: int}>
     */
    private function modelPools(PoolHeadroom $poolHeadroom): array
    {
        $pools = [];

        try {
            foreach (AiModelPrice::query()->whereNotNull('free_usage_pool_id')->get(['provider', 'model', 'free_usage_pool_id']) as $aiModelPrice) {
                $figures = $poolHeadroom->forPool((int) $aiModelPrice->free_usage_pool_id);

                if ($figures instanceof PoolHeadroomFigures) {
                    $pools[sprintf('%s|%s', $aiModelPrice->provider, $aiModelPrice->model)] = $figures->toArray();
                }
            }
        } catch (PoolHeadroomUnavailable) {
            // PoolHeadroom already reported the original failure.
            return [];
        } catch (Throwable $throwable) {
            // Runtime resolution fails closed on the same error; keep the page up.
            report($throwable);

            return [];
        }

        return $pools;
    }

    /**
     * Replace a task scope's tier list, renumbered from 0. A lone tier that
     * inherits everything stores nothing.
     *
     * @param  list<array{provider?: string|null, model?: string|null, reasoning?: string|null, min_pool_percent?: int|null, min_pool_tokens?: int|null}>  $tiers
     */
    private function storeTiers(AiTask $aiTask, string $scope, array $tiers): void
    {
        AiTaskModel::query()->forTask($aiTask)->where('scope', $scope)->get()->each->delete();

        $rows = array_map(static fn (array $tier): array => [
            // A model only makes sense next to a provider.
            'provider' => filled($tier['model'] ?? null) ? ($tier['provider'] ?? null) : null,
            'model' => $tier['model'] ?? null,
            'reasoning' => $tier['reasoning'] ?? null,
            'min_pool_percent' => $tier['min_pool_percent'] ?? null,
            'min_pool_tokens' => $tier['min_pool_tokens'] ?? null,
        ], $tiers);

        if (count($rows) === 1 && array_filter($rows[0], filled(...)) === []) {
            return;
        }

        foreach ($rows as $position => $attributes) {
            AiTaskModel::query()->create(['task' => $aiTask, 'scope' => $scope, 'position' => $position, ...$attributes]);
        }
    }

    /**
     * The failover row: a provider, plus a model only next to a provider.
     *
     * @param  array{provider?: string|null, model?: string|null}  $failover
     */
    private function storeFailover(array $failover): void
    {
        $provider = $failover['provider'] ?? null;
        $existing = AiTaskModel::query()->forTask(AiTask::Failover)->where('scope', AiTaskModel::DEFAULT_SCOPE)->first();

        if (blank($provider)) {
            $existing?->delete();

            return;
        }

        ($existing ?? new AiTaskModel(['task' => AiTask::Failover, 'scope' => AiTaskModel::DEFAULT_SCOPE]))
            ->fill(['provider' => $provider, 'model' => $failover['model'] ?? null, 'reasoning' => null])
            ->save();
    }

    /**
     * The row's provider as the picker shows it. A model saved without a
     * provider (settings from before 1.26.0) runs on `config('ai.default')`,
     * so that is the provider shown and submitted back.
     */
    private function savedProvider(?AiTaskModel $aiTaskModel): ?string
    {
        if (filled($aiTaskModel?->model) && blank($aiTaskModel->provider)) {
            return (string) config('ai.default', 'openai');
        }

        return $aiTaskModel?->provider;
    }

    /**
     * @return array<string, string>
     */
    private function snapshot(): array
    {
        return AiTaskModel::query()
            ->orderBy('task')->orderBy('scope')->orderBy('position')
            ->get()
            ->mapWithKeys(fn (AiTaskModel $aiTaskModel): array => [
                sprintf('%s:%s#%d', $aiTaskModel->task->value, $aiTaskModel->scope, $aiTaskModel->position) => trim(sprintf(
                    '%s/%s · %s%s%s',
                    $aiTaskModel->provider ?? 'inherit',
                    $aiTaskModel->model ?? 'inherit',
                    $aiTaskModel->reasoning->value ?? 'inherit',
                    $aiTaskModel->min_pool_percent === null ? '' : sprintf(' · ≥%d%%', $aiTaskModel->min_pool_percent),
                    $aiTaskModel->min_pool_tokens === null ? '' : sprintf(' · ≥%d tokens', $aiTaskModel->min_pool_tokens),
                )),
            ])
            ->all();
    }

    /**
     * @param  array<string, array{supports_reasoning: bool|null, levels: list<string>|null}>  $modelCapabilities  keyed "provider|model"
     * @return array{provider: string, model: string, reasoning: string, reasoning_label: string, reasoning_hint: string|null, tier: array{position: int, count: int, reason: string|null}|null}
     */
    private function summary(ResolvedSelection $resolvedSelection, array $modelCapabilities): array
    {
        $supported = $modelCapabilities[sprintf('%s|%s', $resolvedSelection->provider, $resolvedSelection->model)]['supports_reasoning'] ?? null;

        return [
            'provider' => $resolvedSelection->provider,
            'model' => $resolvedSelection->model,
            'reasoning' => $resolvedSelection->reasoning->value,
            'reasoning_label' => $resolvedSelection->reasoning->label(),
            'tier' => $resolvedSelection->tier?->toArray(),
            'reasoning_hint' => match (true) {
                ! ReasoningOptions::supportsProvider($resolvedSelection->provider) => __('Not supported by this provider'),
                $supported === false => __("Model doesn't reason"),
                default => null,
            },
        ];
    }
}
