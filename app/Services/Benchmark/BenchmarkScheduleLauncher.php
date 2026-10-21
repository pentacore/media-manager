<?php

declare(strict_types=1);

namespace App\Services\Benchmark;

use App\Enums\BenchmarkRunStatus;
use App\Enums\BenchmarkScheduleOutcome;
use App\Models\BenchmarkCase;
use App\Models\BenchmarkRun;
use App\Models\BenchmarkSchedule;
use App\Models\User;
use App\Notifications\ScheduledBenchmarkAlert;
use App\Services\Audit\AuditLogger;
use App\Services\Notifications\AdminNotifier;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Launches benchmark schedules: due ones from the scheduler sweep, any one
 * from Run now. Each attempt checks, under a lock on the schedule, that no
 * earlier run of it is still going, that its task has cases and tier
 * models, and that a fresh estimate fits its cost cap; then it starts a
 * run of those cases against those models. A due launch advances the
 * schedule first, in its own transaction, so neither downtime nor a crash
 * can launch it twice for one slot.
 */
final readonly class BenchmarkScheduleLauncher
{
    public const string FAILED_MESSAGE = 'The run could not start; the error is in the logs.';

    public function __construct(
        private BenchmarkScheduleClock $benchmarkScheduleClock,
        private BenchmarkTierCandidates $benchmarkTierCandidates,
        private BenchmarkEstimator $benchmarkEstimator,
        private BenchmarkRunLauncher $benchmarkRunLauncher,
        private AdminNotifier $adminNotifier,
        private AuditLogger $auditLogger,
    ) {}

    public function launchDue(int $scheduleId): ?BenchmarkScheduleAttempt
    {
        $benchmarkSchedule = DB::transaction(function () use ($scheduleId): ?BenchmarkSchedule {
            $locked = BenchmarkSchedule::query()->lockForUpdate()->find($scheduleId);

            if (! $locked instanceof BenchmarkSchedule || ! $locked->enabled || $locked->next_run_at === null || $locked->next_run_at->isFuture()) {
                return null;
            }

            $locked->update(['next_run_at' => $this->benchmarkScheduleClock->nextRunAfter($locked, now()->toImmutable())]);

            return $locked;
        });

        if (! $benchmarkSchedule instanceof BenchmarkSchedule) {
            return null;
        }

        $benchmarkScheduleAttempt = $this->attempt($benchmarkSchedule, $benchmarkSchedule->creator);

        if ($benchmarkScheduleAttempt->outcome->isSkip()) {
            $this->adminNotifier->send(ScheduledBenchmarkAlert::skipped($benchmarkSchedule, $benchmarkScheduleAttempt->message));
        }

        return $benchmarkScheduleAttempt;
    }

    public function launchNow(BenchmarkSchedule $benchmarkSchedule, User $user): BenchmarkScheduleAttempt
    {
        return $this->attempt($benchmarkSchedule, $user);
    }

    private function attempt(BenchmarkSchedule $benchmarkSchedule, ?User $user): BenchmarkScheduleAttempt
    {
        try {
            $benchmarkScheduleAttempt = DB::transaction(function () use ($benchmarkSchedule, $user): BenchmarkScheduleAttempt {
                $locked = BenchmarkSchedule::query()->lockForUpdate()->findOrFail($benchmarkSchedule->id);

                return $this->launchLocked($locked, $user);
            });
        } catch (Throwable $throwable) {
            report($throwable);
            $benchmarkScheduleAttempt = new BenchmarkScheduleAttempt(BenchmarkScheduleOutcome::Failed, self::FAILED_MESSAGE);
        }

        $benchmarkSchedule->update([
            'last_run_at' => now(),
            'last_outcome' => $benchmarkScheduleAttempt->outcome,
            'last_message' => Str::limit($benchmarkScheduleAttempt->message, 252),
        ]);

        return $benchmarkScheduleAttempt;
    }

    private function launchLocked(BenchmarkSchedule $benchmarkSchedule, ?User $user): BenchmarkScheduleAttempt
    {
        $running = $benchmarkSchedule->runs()->whereIn('status', [BenchmarkRunStatus::Pending, BenchmarkRunStatus::Running])->latest('id')->first();

        if ($running instanceof BenchmarkRun) {
            return new BenchmarkScheduleAttempt(BenchmarkScheduleOutcome::SkippedRunning, sprintf('The previous run "%s" is still going.', $running->name));
        }

        $cases = $this->cases($benchmarkSchedule);

        if ($cases->isEmpty()) {
            return new BenchmarkScheduleAttempt(BenchmarkScheduleOutcome::SkippedNoCases, $benchmarkSchedule->case_tags === []
                ? sprintf('No %s cases match this schedule.', $benchmarkSchedule->task->label())
                : sprintf('No %s cases carry the tags %s.', $benchmarkSchedule->task->label(), implode(', ', $benchmarkSchedule->case_tags)));
        }

        $candidates = $this->benchmarkTierCandidates->forTask($benchmarkSchedule->task);

        if ($candidates === []) {
            return new BenchmarkScheduleAttempt(BenchmarkScheduleOutcome::SkippedNoCandidates, sprintf('The %s task has no tier models to test.', $benchmarkSchedule->task->label()));
        }

        $estimate = $this->benchmarkEstimator->estimate($cases, $candidates, $benchmarkSchedule->judge);
        $costCap = (float) $benchmarkSchedule->cost_cap_usd;

        if ($estimate['total_usd'] > $costCap) {
            return new BenchmarkScheduleAttempt(BenchmarkScheduleOutcome::SkippedOverCap, sprintf('The estimate %s is over the %s cap.', $this->usd($estimate['total_usd']), $this->usd($costCap)));
        }

        $benchmarkRun = $this->benchmarkRunLauncher->launch(
            sprintf('%s · %s', $benchmarkSchedule->name, now()->toDateString()),
            $cases,
            $candidates,
            $benchmarkSchedule->judge,
            $costCap,
            $user,
            $benchmarkSchedule->id,
        );

        $this->auditLogger->record(
            'benchmark_run.started',
            $benchmarkRun,
            sprintf('Started scheduled benchmark run "%s".', $benchmarkRun->name),
            context: ['cases' => $cases->count(), 'candidates' => count($candidates), 'scheduled' => true, 'schedule_id' => $benchmarkSchedule->id],
        );

        return new BenchmarkScheduleAttempt(BenchmarkScheduleOutcome::Launched, sprintf('Started run "%s".', $benchmarkRun->name), $benchmarkRun);
    }

    /**
     * @return Collection<int, BenchmarkCase>
     */
    private function cases(BenchmarkSchedule $benchmarkSchedule): Collection
    {
        return BenchmarkCase::query()
            ->where('task', $benchmarkSchedule->task)
            ->when($benchmarkSchedule->case_tags !== [], static fn (Builder $builder): Builder => $builder->where(static function (Builder $tags) use ($benchmarkSchedule): void {
                foreach ($benchmarkSchedule->case_tags as $tag) {
                    $tags->orWhereJsonContains('tags', $tag);
                }
            }))
            ->orderBy('id')
            ->get();
    }

    private function usd(float $usd): string
    {
        return '$'.number_format($usd, $usd < 1 ? 4 : 2);
    }
}
