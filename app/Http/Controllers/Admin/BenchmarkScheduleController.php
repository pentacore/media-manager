<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Ai\ModelCatalog;
use App\Ai\TaskModelResolver;
use App\Enums\AiReasoningLevel;
use App\Enums\AiTask;
use App\Enums\BenchmarkScheduleFrequency;
use App\Enums\BenchmarkScheduleOutcome;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreBenchmarkScheduleRequest;
use App\Http\Requests\Admin\ToggleBenchmarkScheduleRequest;
use App\Models\BenchmarkCase;
use App\Models\BenchmarkRun;
use App\Models\BenchmarkSchedule;
use App\Services\Audit\AuditLogger;
use App\Services\Benchmark\BenchmarkScheduleClock;
use App\Services\Benchmark\BenchmarkScheduleLauncher;
use App\Services\Benchmark\BenchmarkTierCandidates;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Inertia\Inertia;
use Inertia\Response;

class BenchmarkScheduleController extends Controller
{
    private const int PER_PAGE = 25;

    public function index(): Response
    {
        $lengthAwarePaginator = BenchmarkSchedule::query()
            ->with('latestRun:id,benchmark_schedule_id,name,status')
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return Inertia::render('Admin/Benchmarks/Schedules/Index', [
            'timezone' => config('app.timezone'),
            'schedules' => [
                'data' => $lengthAwarePaginator->getCollection()->map(static fn (BenchmarkSchedule $benchmarkSchedule): array => [
                    'id' => $benchmarkSchedule->id,
                    'name' => $benchmarkSchedule->name,
                    'task' => $benchmarkSchedule->task->value,
                    'task_label' => $benchmarkSchedule->task->label(),
                    'cadence' => $benchmarkSchedule->cadenceLabel(),
                    'enabled' => $benchmarkSchedule->enabled,
                    'next_run_at' => $benchmarkSchedule->next_run_at?->toIso8601String(),
                    'last_run_at' => $benchmarkSchedule->last_run_at?->toIso8601String(),
                    'last_outcome' => $benchmarkSchedule->last_outcome?->value,
                    'last_outcome_label' => $benchmarkSchedule->last_outcome?->label(),
                    'last_message' => $benchmarkSchedule->last_message,
                    'latest_run' => $benchmarkSchedule->latestRun === null ? null : [
                        'id' => $benchmarkSchedule->latestRun->id,
                        'name' => $benchmarkSchedule->latestRun->name,
                        'status' => $benchmarkSchedule->latestRun->status->value,
                        'status_label' => $benchmarkSchedule->latestRun->status->label(),
                    ],
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

    public function create(TaskModelResolver $taskModelResolver, ModelCatalog $modelCatalog, BenchmarkTierCandidates $benchmarkTierCandidates): Response
    {
        return $this->form(null, $taskModelResolver, $modelCatalog, $benchmarkTierCandidates);
    }

    public function edit(BenchmarkSchedule $benchmarkSchedule, TaskModelResolver $taskModelResolver, ModelCatalog $modelCatalog, BenchmarkTierCandidates $benchmarkTierCandidates): Response
    {
        return $this->form($benchmarkSchedule, $taskModelResolver, $modelCatalog, $benchmarkTierCandidates);
    }

    public function store(StoreBenchmarkScheduleRequest $storeBenchmarkScheduleRequest, BenchmarkScheduleClock $benchmarkScheduleClock, AuditLogger $auditLogger): RedirectResponse
    {
        $benchmarkSchedule = new BenchmarkSchedule([
            ...$this->attributes($storeBenchmarkScheduleRequest->validated()),
            'created_by' => $storeBenchmarkScheduleRequest->user()->id,
        ]);
        $this->schedule($benchmarkSchedule, $benchmarkScheduleClock);
        $benchmarkSchedule->save();

        $auditLogger->record('benchmark_schedule.created', $benchmarkSchedule, sprintf('Created benchmark schedule "%s".', $benchmarkSchedule->name));
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Benchmark schedule created.')]);

        return to_route('admin.benchmarks.schedules.index');
    }

    public function update(StoreBenchmarkScheduleRequest $storeBenchmarkScheduleRequest, BenchmarkSchedule $benchmarkSchedule, BenchmarkScheduleClock $benchmarkScheduleClock, AuditLogger $auditLogger): RedirectResponse
    {
        $benchmarkSchedule->fill($this->attributes($storeBenchmarkScheduleRequest->validated()));
        $this->schedule($benchmarkSchedule, $benchmarkScheduleClock);
        $benchmarkSchedule->save();

        $auditLogger->record('benchmark_schedule.updated', $benchmarkSchedule, sprintf('Updated benchmark schedule "%s".', $benchmarkSchedule->name));
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Benchmark schedule saved.')]);

        return to_route('admin.benchmarks.schedules.index');
    }

    public function toggle(ToggleBenchmarkScheduleRequest $toggleBenchmarkScheduleRequest, BenchmarkSchedule $benchmarkSchedule, BenchmarkScheduleClock $benchmarkScheduleClock, AuditLogger $auditLogger): RedirectResponse
    {
        $benchmarkSchedule->enabled = (bool) $toggleBenchmarkScheduleRequest->validated()['enabled'];
        $this->schedule($benchmarkSchedule, $benchmarkScheduleClock);
        $benchmarkSchedule->save();

        $auditLogger->record(
            $benchmarkSchedule->enabled ? 'benchmark_schedule.enabled' : 'benchmark_schedule.disabled',
            $benchmarkSchedule,
            sprintf('%s benchmark schedule "%s".', $benchmarkSchedule->enabled ? 'Enabled' : 'Paused', $benchmarkSchedule->name),
        );
        Inertia::flash('toast', ['type' => 'success', 'message' => $benchmarkSchedule->enabled ? __('Schedule enabled.') : __('Schedule paused.')]);

        return back();
    }

    public function run(Request $request, BenchmarkSchedule $benchmarkSchedule, BenchmarkScheduleLauncher $benchmarkScheduleLauncher, AuditLogger $auditLogger): RedirectResponse
    {
        $benchmarkScheduleAttempt = $benchmarkScheduleLauncher->launchNow($benchmarkSchedule, $request->user());

        $auditLogger->record('benchmark_schedule.run_now', $benchmarkSchedule, sprintf('Ran benchmark schedule "%s" now.', $benchmarkSchedule->name), context: ['outcome' => $benchmarkScheduleAttempt->outcome->value]);

        if ($benchmarkScheduleAttempt->benchmarkRun instanceof BenchmarkRun) {
            Inertia::flash('toast', ['type' => 'success', 'message' => __('Benchmark run started.')]);

            return to_route('admin.benchmarks.runs.show', $benchmarkScheduleAttempt->benchmarkRun);
        }

        Inertia::flash('toast', ['type' => $benchmarkScheduleAttempt->outcome === BenchmarkScheduleOutcome::Failed ? 'error' : 'info', 'message' => $benchmarkScheduleAttempt->message]);

        return back();
    }

    public function destroy(BenchmarkSchedule $benchmarkSchedule, AuditLogger $auditLogger): RedirectResponse
    {
        $benchmarkSchedule->delete();

        $auditLogger->record('benchmark_schedule.deleted', null, sprintf('Deleted benchmark schedule "%s".', $benchmarkSchedule->name), context: ['schedule_id' => $benchmarkSchedule->id]);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Benchmark schedule deleted.')]);

        return to_route('admin.benchmarks.schedules.index');
    }

    /**
     * The validated input as model attributes: the day field the frequency
     * does not use is cleared.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function attributes(array $validated): array
    {
        $benchmarkScheduleFrequency = BenchmarkScheduleFrequency::from($validated['frequency']);

        return [
            ...Arr::only($validated, ['name', 'task', 'case_tags', 'judge', 'cost_cap_usd', 'time_of_day', 'pass_rate_threshold', 'score_threshold', 'enabled']),
            'frequency' => $benchmarkScheduleFrequency,
            'weekday' => $benchmarkScheduleFrequency === BenchmarkScheduleFrequency::Weekly ? (int) $validated['weekday'] : null,
            'day_of_month' => $benchmarkScheduleFrequency === BenchmarkScheduleFrequency::Monthly ? (int) $validated['day_of_month'] : null,
        ];
    }

    private function schedule(BenchmarkSchedule $benchmarkSchedule, BenchmarkScheduleClock $benchmarkScheduleClock): void
    {
        $benchmarkSchedule->next_run_at = $benchmarkSchedule->enabled
            ? $benchmarkScheduleClock->nextRunAfter($benchmarkSchedule, now()->toImmutable())
            : null;
    }

    private function form(?BenchmarkSchedule $benchmarkSchedule, TaskModelResolver $taskModelResolver, ModelCatalog $modelCatalog, BenchmarkTierCandidates $benchmarkTierCandidates): Response
    {
        $resolvedSelection = $taskModelResolver->resolve(AiTask::BenchmarkJudge);

        return Inertia::render('Admin/Benchmarks/Schedules/Form', [
            'schedule' => $benchmarkSchedule instanceof BenchmarkSchedule ? [
                'id' => $benchmarkSchedule->id,
                'name' => $benchmarkSchedule->name,
                'task' => $benchmarkSchedule->task->value,
                'case_tags' => $benchmarkSchedule->case_tags,
                'judge' => $benchmarkSchedule->judge,
                'cost_cap_usd' => (float) $benchmarkSchedule->cost_cap_usd,
                'frequency' => $benchmarkSchedule->frequency->value,
                'time_of_day' => $benchmarkSchedule->time_of_day,
                'weekday' => $benchmarkSchedule->weekday,
                'day_of_month' => $benchmarkSchedule->day_of_month,
                'pass_rate_threshold' => (float) $benchmarkSchedule->pass_rate_threshold,
                'score_threshold' => (float) $benchmarkSchedule->score_threshold,
                'enabled' => $benchmarkSchedule->enabled,
            ] : null,
            'tasks' => array_values(array_map(
                static fn (AiTask $aiTask): array => ['name' => $aiTask->label(), 'value' => $aiTask->value],
                array_filter(AiTask::cases(), static fn (AiTask $aiTask): bool => $aiTask->isBenchmarkable()),
            )),
            'cases' => BenchmarkCase::query()->orderBy('id')->get(['id', 'task', 'tags'])->map(static fn (BenchmarkCase $benchmarkCase): array => [
                'id' => $benchmarkCase->id,
                'task' => $benchmarkCase->task->value,
                'tags' => $benchmarkCase->tags,
            ])->all(),
            'frequencies' => array_map(static fn (BenchmarkScheduleFrequency $frequency): array => ['label' => $frequency->label(), 'value' => $frequency->value], BenchmarkScheduleFrequency::cases()),
            'weekdays' => array_map(static fn (int $value, string $label): array => ['label' => $label, 'value' => $value], array_keys(BenchmarkSchedule::weekdays()), BenchmarkSchedule::weekdays()),
            'models' => $modelCatalog->modelsByConfiguredProvider(),
            'timezone' => config('app.timezone'),
            'reasoningLevels' => AiReasoningLevel::mapForSelect(labelKey: 'label'),
            'modelCapabilities' => $modelCatalog->reasoningCapabilities(),
            'reasoningProviders' => $modelCatalog->reasoningProviders(),
            'judgeDefault' => [
                'provider' => $resolvedSelection->provider,
                'model' => $resolvedSelection->model,
                'reasoning' => $resolvedSelection->reasoning->value,
            ],
            'currentTiers' => $benchmarkTierCandidates->all(),
        ]);
    }
}
