<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Ai\ModelCatalog;
use App\Ai\TaskModelResolver;
use App\Enums\AiReasoningLevel;
use App\Enums\AiTask;
use App\Enums\BenchmarkJudgeStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\EstimateNeutralRejudgeRequest;
use App\Http\Requests\Admin\ShowBenchmarkComparisonRequest;
use App\Http\Requests\Admin\StartNeutralRejudgeRequest;
use App\Models\BenchmarkNeutralJudgement;
use App\Models\BenchmarkNeutralRejudge;
use App\Models\BenchmarkResult;
use App\Models\BenchmarkRun;
use App\Providers\AIServiceProvider;
use App\Services\Audit\AuditLogger;
use App\Services\Benchmark\BenchmarkComparison;
use App\Services\Benchmark\BenchmarkNeutralRejudgeLauncher;
use App\Services\Benchmark\BenchmarkResultDetails;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
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

    public function estimate(EstimateNeutralRejudgeRequest $estimateNeutralRejudgeRequest, BenchmarkNeutralRejudgeLauncher $benchmarkNeutralRejudgeLauncher): JsonResponse
    {
        $validated = $estimateNeutralRejudgeRequest->validated();
        $judge = $this->judgeFrom($validated['judge']);

        return response()->json($benchmarkNeutralRejudgeLauncher->estimate(
            $benchmarkNeutralRejudgeLauncher->scope(array_map(intval(...), $validated['run_ids']), BenchmarkNeutralJudgement::keyFor($judge)),
            $judge,
        ));
    }

    public function start(StartNeutralRejudgeRequest $startNeutralRejudgeRequest, BenchmarkNeutralRejudgeLauncher $benchmarkNeutralRejudgeLauncher, AuditLogger $auditLogger): RedirectResponse
    {
        $validated = $startNeutralRejudgeRequest->validated();
        $runIds = array_map(intval(...), $validated['run_ids']);
        $judge = $this->judgeFrom($validated['judge']);
        $costCap = (float) $validated['cost_cap_usd'];
        $scope = $benchmarkNeutralRejudgeLauncher->scope($runIds, BenchmarkNeutralJudgement::keyFor($judge));

        throw_if($scope->isEmpty(), ValidationException::withMessages(['judge' => __('Every result already has a score from this judge')]));

        $estimate = $benchmarkNeutralRejudgeLauncher->estimate($scope, $judge);

        throw_if($costCap < $estimate['total_usd'], ValidationException::withMessages(['cost_cap_usd' => __('The cost cap is below the estimate.')]));

        $benchmarkNeutralRejudge = $benchmarkNeutralRejudgeLauncher->start($runIds, $judge, $costCap, $estimate['total_usd'], $startNeutralRejudgeRequest->user());

        throw_unless($benchmarkNeutralRejudge instanceof BenchmarkNeutralRejudge, ValidationException::withMessages(['judge' => __('Every result already has a score from this judge')]));

        $auditLogger->record(
            'benchmark.neutral_rejudge.started',
            $benchmarkNeutralRejudge,
            sprintf('Started a neutral re-judge of runs %s with %s.', implode(', ', $benchmarkNeutralRejudge->run_ids), BenchmarkComparison::candidateLabel($judge)),
            context: ['run_ids' => $benchmarkNeutralRejudge->run_ids, 'judge' => $judge, 'cost_cap_usd' => $costCap, 'results' => $estimate['results']],
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Neutral re-judge started.')]);

        return back();
    }

    public function cancel(BenchmarkNeutralRejudge $benchmarkNeutralRejudge, BenchmarkNeutralRejudgeLauncher $benchmarkNeutralRejudgeLauncher, AuditLogger $auditLogger): RedirectResponse
    {
        if (! $benchmarkNeutralRejudgeLauncher->cancel($benchmarkNeutralRejudge)) {
            Inertia::flash('toast', ['type' => 'info', 'message' => __('This re-judge has already finished.')]);

            return back();
        }

        $auditLogger->record('benchmark.neutral_rejudge.cancelled', $benchmarkNeutralRejudge, sprintf('Cancelled neutral re-judge #%d.', $benchmarkNeutralRejudge->id));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Neutral re-judge cancelled.')]);

        return back();
    }

    /**
     * @param  array<string, mixed>  $judge
     * @return array{provider: string, model: string, reasoning: string|null}
     */
    private function judgeFrom(array $judge): array
    {
        return [
            'provider' => (string) $judge['provider'],
            'model' => (string) $judge['model'],
            'reasoning' => is_string($judge['reasoning'] ?? null) ? $judge['reasoning'] : null,
        ];
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
