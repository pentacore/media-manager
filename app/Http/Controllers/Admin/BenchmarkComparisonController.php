<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Ai\ModelCatalog;
use App\Ai\TaskModelResolver;
use App\Enums\AiReasoningLevel;
use App\Enums\AiTask;
use App\Enums\BenchmarkJudgeStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ShowBenchmarkComparisonRequest;
use App\Models\BenchmarkNeutralRejudge;
use App\Models\BenchmarkResult;
use App\Models\BenchmarkRun;
use App\Providers\AIServiceProvider;
use App\Services\Benchmark\BenchmarkComparison;
use App\Services\Benchmark\BenchmarkResultDetails;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class BenchmarkComparisonController extends Controller
{
    private const int PICKABLE_RUNS = 50;

    public function show(ShowBenchmarkComparisonRequest $showBenchmarkComparisonRequest, BenchmarkComparison $benchmarkComparison, TaskModelResolver $taskModelResolver, ModelCatalog $modelCatalog): Response
    {
        $validated = $showBenchmarkComparisonRequest->validated();
        $runIds = array_map(intval(...), $validated['run_ids']);
        $comparison = $benchmarkComparison->build(
            $runIds,
            isset($validated['baseline']) ? (int) $validated['baseline'] : null,
            BenchmarkComparison::parsePairs($validated['pairs'] ?? null),
            $validated['scores'] ?? null,
        );
        $resolvedSelection = $taskModelResolver->resolve(AiTask::BenchmarkJudge);

        return Inertia::render('Admin/Benchmarks/Compare', [
            'comparison' => $comparison,
            'rejudge' => $this->rejudgeSummary($runIds),
            'pickableRuns' => $this->pickableRuns(),
            'judgeOptions' => [
                'models' => $modelCatalog->modelsByConfiguredProvider(),
                'reasoningLevels' => AiReasoningLevel::mapForSelect(labelKey: 'label'),
                'modelCapabilities' => $modelCatalog->reasoningCapabilities(),
                'reasoningProviders' => $modelCatalog->reasoningProviders(),
                'judgeDefault' => [
                    'provider' => $resolvedSelection->provider,
                    'model' => $resolvedSelection->model,
                    'reasoning' => $resolvedSelection->reasoning->value,
                ],
            ],
            'aiEnabled' => AIServiceProvider::enabled(),
            'resultDetail' => Inertia::optional(fn (): array => BenchmarkResultDetails::for(
                BenchmarkResult::query()->whereIn('benchmark_run_id', $runIds)->findOrFail($showBenchmarkComparisonRequest->integer('result')),
            )),
            'outputs' => Inertia::optional(fn (): array => $benchmarkComparison->outputs(
                $runIds,
                array_values(array_map(intval(...), array_filter(explode(',', (string) $showBenchmarkComparisonRequest->query('outputs')), is_numeric(...)))),
                $comparison['score_source']['current'],
            )),
        ]);
    }

    /**
     * The latest neutral re-judge of exactly these runs, with its progress.
     *
     * @param  list<int>  $runIds
     * @return array<string, mixed>|null
     */
    private function rejudgeSummary(array $runIds): ?array
    {
        sort($runIds);

        $benchmarkNeutralRejudge = BenchmarkNeutralRejudge::query()
            ->whereRaw('run_ids::jsonb = ?::jsonb', [json_encode($runIds)])
            ->latest('id')
            ->first();

        if (! $benchmarkNeutralRejudge instanceof BenchmarkNeutralRejudge) {
            return null;
        }

        $counts = $benchmarkNeutralRejudge->judgements()->select('status', DB::raw('count(*) as total'))->groupBy('status')->pluck('total', 'status');
        $count = static fn (BenchmarkJudgeStatus $status): int => (int) ($counts[$status->value] ?? 0);

        return [
            'id' => $benchmarkNeutralRejudge->id,
            'status' => $benchmarkNeutralRejudge->status->value,
            'status_label' => $benchmarkNeutralRejudge->status->label(),
            'judge_key' => $benchmarkNeutralRejudge->judge_key,
            'judge_label' => BenchmarkComparison::candidateLabel($benchmarkNeutralRejudge->judge),
            'estimate_usd' => (float) $benchmarkNeutralRejudge->estimate_usd,
            'cost_cap_usd' => (float) $benchmarkNeutralRejudge->cost_cap_usd,
            'cost_usd' => (float) $benchmarkNeutralRejudge->cost_usd,
            'total' => (int) $counts->sum(),
            'done' => $count(BenchmarkJudgeStatus::Ok) + $count(BenchmarkJudgeStatus::Failed),
            'failed' => $count(BenchmarkJudgeStatus::Failed),
        ];
    }

    /**
     * @return list<array{id: int, name: string, created_at: string|null, task_labels: list<string>}>
     */
    private function pickableRuns(): array
    {
        $runs = BenchmarkRun::query()->comparable()->latest('id')->limit(self::PICKABLE_RUNS)->get(['id', 'name', 'created_at']);
        $tasks = BenchmarkResult::query()
            ->whereIn('benchmark_run_id', $runs->pluck('id'))
            ->distinct()
            ->get(['benchmark_run_id', 'case_task'])
            ->groupBy('benchmark_run_id');

        return $runs->map(static fn (BenchmarkRun $benchmarkRun): array => [
            'id' => $benchmarkRun->id,
            'name' => $benchmarkRun->name,
            'created_at' => $benchmarkRun->created_at?->toIso8601String(),
            'task_labels' => $tasks->get($benchmarkRun->id, collect())
                ->map(static fn (BenchmarkResult $benchmarkResult): string => AiTask::tryFrom($benchmarkResult->case_task)?->label() ?? $benchmarkResult->case_task)
                ->sort()
                ->values()
                ->all(),
        ])->values()->all();
    }
}
