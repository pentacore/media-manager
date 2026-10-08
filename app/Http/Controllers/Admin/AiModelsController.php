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
use App\Services\AiUsage\PoolHeadroom;
use App\Services\AiUsage\PoolHeadroomFigures;
use App\Services\Audit\AuditLogger;
use App\Settings\DecisionAgentSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Collection;
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
        PoolHeadroom $poolHeadroom,
    ): Response {
        $allowlist = $decisionAgentSettings->eventAllowlist();

        $tasks = [];

        foreach (AiTask::cases() as $aiTask) {
            if ($aiTask === AiTask::Failover) {
                continue;
            }

            $tasks[] = [
                'task' => $aiTask->value,
                'label' => $aiTask->label(),
                'inherits_chat' => $aiTask->inheritsChatModel(),
                'has_reasoning' => $aiTask->hasReasoning(),
                'allow_auto' => $aiTask === AiTask::Title,
                'tiers' => $this->tierRows($taskModelResolver->tiers($aiTask)),
                'resolved' => $this->summary($taskModelResolver->resolve($aiTask)),
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
                'resolved' => $this->summary($taskModelResolver->resolve(AiTask::Decision, $scope)),
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

        foreach (AiModelPrice::query()->whereNotNull('free_usage_pool_id')->get(['provider', 'model', 'free_usage_pool_id']) as $aiModelPrice) {
            $figures = $poolHeadroom->forPool((int) $aiModelPrice->free_usage_pool_id);

            if ($figures instanceof PoolHeadroomFigures) {
                $pools[sprintf('%s|%s', $aiModelPrice->provider, $aiModelPrice->model)] = $figures->toArray();
            }
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
        ], array_values($tiers));

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
     * @return array{provider: string, model: string, reasoning: string, reasoning_label: string, reasoning_hint: string|null, tier: array{position: int, count: int, reason: string|null}|null}
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
            'tier' => $resolvedSelection->tier?->toArray(),
            'reasoning_hint' => match (true) {
                ! ReasoningOptions::supportsProvider($resolvedSelection->provider) => __('Not supported by this provider'),
                $supported === false => __("Model doesn't reason"),
                default => null,
            },
        ];
    }
}
