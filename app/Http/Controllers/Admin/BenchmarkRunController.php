<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Ai\ModelCatalog;
use App\Ai\ReasoningOptions;
use App\Ai\TaskModelResolver;
use App\Enums\AiReasoningLevel;
use App\Enums\AiTask;
use App\Enums\BenchmarkResultStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\EstimateBenchmarkRunRequest;
use App\Http\Requests\Admin\StoreBenchmarkRunRequest;
use App\Models\BenchmarkCase;
use App\Models\BenchmarkResult;
use App\Models\BenchmarkRun;
use App\Services\Audit\AuditLogger;
use App\Services\Benchmark\BenchmarkEstimator;
use App\Services\Benchmark\BenchmarkMatrix;
use App\Services\Benchmark\BenchmarkRunLauncher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class BenchmarkRunController extends Controller
{
    private const int PER_PAGE = 25;

    /** How far the confirmed estimate may drift from a fresh one, in USD. */
    private const float ESTIMATE_TOLERANCE_USD = 0.01;

    public function index(): Response
    {
        $lengthAwarePaginator = BenchmarkRun::query()
            ->with('creator:id,name')
            ->latest()
            ->latest('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return Inertia::render('Admin/Benchmarks/Runs/Index', [
            'runs' => [
                'data' => $lengthAwarePaginator->getCollection()->map(static fn (BenchmarkRun $benchmarkRun): array => [
                    'id' => $benchmarkRun->id,
                    'name' => $benchmarkRun->name,
                    'status' => $benchmarkRun->status->value,
                    'status_label' => $benchmarkRun->status->label(),
                    'case_count' => count($benchmarkRun->case_ids),
                    'candidate_count' => count($benchmarkRun->candidates),
                    'estimate_usd' => (float) $benchmarkRun->estimate_usd,
                    'cost_usd' => (float) $benchmarkRun->cost_usd,
                    'created_by' => $benchmarkRun->creator?->name,
                    'created_at' => $benchmarkRun->created_at?->toIso8601String(),
                ])->all(),
                'links' => $lengthAwarePaginator->linkCollection()->toArray(),
                'meta' => [
                    'current_page' => $lengthAwarePaginator->currentPage(),
                    'last_page' => $lengthAwarePaginator->lastPage(),
                    'total' => $lengthAwarePaginator->total(),
                    'per_page' => $lengthAwarePaginator->perPage(),
                ],
            ],
        ]);
    }

    public function create(TaskModelResolver $taskModelResolver, ModelCatalog $modelCatalog): Response
    {
        $resolvedSelection = $taskModelResolver->resolve(AiTask::BenchmarkJudge);

        return Inertia::render('Admin/Benchmarks/Runs/Create', [
            'cases' => BenchmarkCase::query()
                ->orderBy('task')
                ->orderBy('name')
                ->get()
                ->map(static fn (BenchmarkCase $benchmarkCase): array => [
                    'id' => $benchmarkCase->id,
                    'task' => $benchmarkCase->task->value,
                    'task_label' => $benchmarkCase->task->label(),
                    'name' => $benchmarkCase->name,
                    'tags' => $benchmarkCase->tags,
                ])->all(),
            'tasks' => $this->taskOptions(),
            'models' => $modelCatalog->modelsByConfiguredProvider(),
            'providers' => $modelCatalog->textProviders(),
            'reasoningLevels' => AiReasoningLevel::mapForSelect(labelKey: 'label'),
            'modelCapabilities' => $modelCatalog->reasoningCapabilities(),
            'reasoningProviders' => array_values(array_filter($modelCatalog->textProviders(), ReasoningOptions::supportsProvider(...))),
            'judgeDefault' => [
                'provider' => $resolvedSelection->provider,
                'model' => $resolvedSelection->model,
                'reasoning' => $resolvedSelection->reasoning->value,
            ],
            'currentTiers' => $this->currentTiers($taskModelResolver),
        ]);
    }

    public function estimate(EstimateBenchmarkRunRequest $estimateBenchmarkRunRequest, BenchmarkEstimator $benchmarkEstimator): JsonResponse
    {
        $validated = $estimateBenchmarkRunRequest->validated();

        return response()->json($benchmarkEstimator->estimate(
            $this->casesFor($validated['case_ids']),
            $validated['candidates'],
            $validated['judge'],
        ));
    }

    public function store(StoreBenchmarkRunRequest $storeBenchmarkRunRequest, BenchmarkEstimator $benchmarkEstimator, BenchmarkRunLauncher $benchmarkRunLauncher, AuditLogger $auditLogger): RedirectResponse
    {
        $validated = $storeBenchmarkRunRequest->validated();
        $cases = $this->casesFor($validated['case_ids']);

        $estimate = $benchmarkEstimator->estimate($cases, $validated['candidates'], $validated['judge']);

        if (abs($estimate['total_usd'] - (float) $validated['confirm_estimate_usd']) > self::ESTIMATE_TOLERANCE_USD) {
            throw ValidationException::withMessages(['confirm_estimate_usd' => __('The estimate changed — review it again.')]);
        }

        $benchmarkRun = $benchmarkRunLauncher->launch(
            $validated['name'],
            $cases,
            $validated['candidates'],
            $validated['judge'],
            isset($validated['cost_cap_usd']) ? (float) $validated['cost_cap_usd'] : null,
            $storeBenchmarkRunRequest->user(),
        );

        $auditLogger->record(
            'benchmark_run.started',
            $benchmarkRun,
            sprintf('Started benchmark run "%s".', $benchmarkRun->name),
            context: ['cases' => $cases->count(), 'candidates' => count($validated['candidates'])],
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Benchmark run started.')]);

        return to_route('admin.benchmarks.runs.show', $benchmarkRun);
    }

    public function show(Request $request, BenchmarkRun $benchmarkRun): Response
    {
        $counts = $benchmarkRun->results()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');
        $count = static fn (BenchmarkResultStatus $status): int => (int) ($counts[$status->value] ?? 0);

        return Inertia::render('Admin/Benchmarks/Runs/Show', [
            'run' => [
                'id' => $benchmarkRun->id,
                'name' => $benchmarkRun->name,
                'status' => $benchmarkRun->status->value,
                'status_label' => $benchmarkRun->status->label(),
                'candidates' => $benchmarkRun->candidates,
                'judge' => $benchmarkRun->judge,
                'estimate_usd' => (float) $benchmarkRun->estimate_usd,
                'cost_usd' => (float) $benchmarkRun->cost_usd,
                'cost_cap_usd' => $benchmarkRun->cost_cap_usd === null ? null : (float) $benchmarkRun->cost_cap_usd,
                'started_at' => $benchmarkRun->started_at?->toIso8601String(),
                'finished_at' => $benchmarkRun->finished_at?->toIso8601String(),
                'counts' => [
                    'total' => (int) $counts->sum(),
                    'pending' => $count(BenchmarkResultStatus::Pending),
                    'running' => $count(BenchmarkResultStatus::Running),
                    // Running under a claim whose worker died: read-only here,
                    // the next settle of the run fails them.
                    'stalled' => $benchmarkRun->results()->runStale()->count(),
                    'ok' => $count(BenchmarkResultStatus::Ok),
                    'failed' => $count(BenchmarkResultStatus::Failed),
                    'skipped' => $count(BenchmarkResultStatus::Skipped),
                ],
            ],
            'matrix' => BenchmarkMatrix::for($benchmarkRun),
            'resultDetail' => Inertia::optional(fn (): array => $this->resultDetail($benchmarkRun, $request->integer('result'))),
        ]);
    }

    public function cancel(BenchmarkRun $benchmarkRun, BenchmarkRunLauncher $benchmarkRunLauncher, AuditLogger $auditLogger): RedirectResponse
    {
        if ($benchmarkRun->isTerminal()) {
            Inertia::flash('toast', ['type' => 'info', 'message' => __('This run has already finished.')]);

            return back();
        }

        $benchmarkRunLauncher->cancel($benchmarkRun);

        $auditLogger->record('benchmark_run.cancelled', $benchmarkRun, sprintf('Cancelled benchmark run "%s".', $benchmarkRun->name));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Benchmark run cancelled.')]);

        return back();
    }

    public function rejudge(BenchmarkRun $benchmarkRun, BenchmarkRunLauncher $benchmarkRunLauncher): RedirectResponse
    {
        $queued = $benchmarkRunLauncher->rejudge($benchmarkRun);

        if ($queued === 0) {
            Inertia::flash('toast', ['type' => 'info', 'message' => __('No unscored results to re-judge.')]);

            return back();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => trans_choice('Re-judging :count result.|Re-judging :count results.', $queued, ['count' => $queued])]);

        return back();
    }

    public function destroy(BenchmarkRun $benchmarkRun, BenchmarkRunLauncher $benchmarkRunLauncher, AuditLogger $auditLogger): RedirectResponse
    {
        $refusal = $benchmarkRunLauncher->delete($benchmarkRun);

        if ($refusal !== null) {
            Inertia::flash('toast', ['type' => 'error', 'message' => $refusal]);

            return back();
        }

        $auditLogger->record('benchmark_run.deleted', $benchmarkRun, sprintf('Deleted benchmark run "%s".', $benchmarkRun->name));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Benchmark run deleted.')]);

        return to_route('admin.benchmarks.runs.index');
    }

    /**
     * @param  list<int>  $caseIds
     * @return Collection<int, BenchmarkCase>
     */
    private function casesFor(array $caseIds): Collection
    {
        $cases = BenchmarkCase::query()->whereIn('id', $caseIds)->get()->keyBy('id');

        return collect($caseIds)
            ->map(static fn (int|string $caseId): ?BenchmarkCase => $cases->get((int) $caseId))
            ->filter()
            ->unique('id')
            ->values();
    }

    /**
     * Every candidate's result for the clicked result's case, with the case's
     * scoring and prompt when the case still exists.
     *
     * @return array<string, mixed>
     */
    private function resultDetail(BenchmarkRun $benchmarkRun, int $resultId): array
    {
        $clicked = $benchmarkRun->results()->findOrFail($resultId);

        $siblings = $benchmarkRun->results()
            ->where('case_task', $clicked->case_task)
            ->when(
                $clicked->benchmark_case_id === null,
                static fn ($query) => $query->whereNull('benchmark_case_id')->where('case_name', $clicked->case_name),
                static fn ($query) => $query->where('benchmark_case_id', $clicked->benchmark_case_id),
            )
            ->orderBy('candidate_index')
            ->get();

        $benchmarkCase = $clicked->benchmarkCase;

        return [
            'case_id' => $clicked->benchmark_case_id,
            'case_name' => $clicked->case_name,
            'case_task' => $clicked->case_task,
            'scoring' => $benchmarkCase?->scoring,
            'prompt' => $benchmarkCase?->input['prompt'] ?? null,
            'results' => $siblings->map(static fn (BenchmarkResult $benchmarkResult): array => [
                'result_id' => $benchmarkResult->id,
                'candidate_index' => $benchmarkResult->candidate_index,
                'status' => $benchmarkResult->status->value,
                'output' => $benchmarkResult->output,
                'intended_actions' => $benchmarkResult->intended_actions,
                'score' => $benchmarkResult->score,
                'passed' => $benchmarkResult->passed,
                'judge_status' => $benchmarkResult->judge_status->value,
                'judge_stale' => $benchmarkResult->isJudgeStale(),
                'stale' => $benchmarkResult->isRunStale(),
                'score_detail' => collect($benchmarkResult->score_detail ?? [])->except('tool_log')->all(),
                'error' => $benchmarkResult->error,
                'tool_log' => $benchmarkResult->score_detail['tool_log'] ?? [],
                'latency_ms' => $benchmarkResult->latency_ms,
                'cost_usd' => (float) $benchmarkResult->cost_usd + (float) $benchmarkResult->judge_cost_usd,
            ])->all(),
        ];
    }

    /**
     * Per benchmarkable task, the models its tier list runs on now: every
     * explicit tier plus the resolved pair when a tier inherits.
     *
     * @return array<string, list<array{provider: string, model: string, reasoning: string}>>
     */
    private function currentTiers(TaskModelResolver $taskModelResolver): array
    {
        $tiers = [];

        foreach (AiTask::cases() as $aiTask) {
            if (! $aiTask->isBenchmarkable()) {
                continue;
            }

            $selections = [];
            $inherits = false;

            foreach ($taskModelResolver->tiers($aiTask) as $aiTaskModel) {
                if (blank($aiTaskModel->model) || $aiTaskModel->model === 'auto') {
                    $inherits = true;

                    continue;
                }

                $selections[] = [
                    'provider' => (string) ($aiTaskModel->provider ?: config('ai.default', 'openai')),
                    'model' => (string) $aiTaskModel->model,
                    'reasoning' => ($aiTaskModel->reasoning ?? AiReasoningLevel::ProviderDefault)->value,
                ];
            }

            if ($inherits || $selections === []) {
                $resolved = $taskModelResolver->resolve($aiTask);
                $selections[] = [
                    'provider' => $resolved->provider,
                    'model' => $resolved->model,
                    'reasoning' => $resolved->reasoning->value,
                ];
            }

            $tiers[$aiTask->value] = array_values(array_unique($selections, SORT_REGULAR));
        }

        return $tiers;
    }

    /**
     * @return list<array{name: string, value: string}>
     */
    private function taskOptions(): array
    {
        return array_values(array_map(
            static fn (AiTask $aiTask): array => ['name' => $aiTask->label(), 'value' => $aiTask->value],
            array_filter(AiTask::cases(), static fn (AiTask $aiTask): bool => $aiTask->isBenchmarkable()),
        ));
    }
}
