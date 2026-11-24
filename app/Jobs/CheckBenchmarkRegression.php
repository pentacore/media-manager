<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\BenchmarkRun;
use App\Notifications\ScheduledBenchmarkAlert;
use App\Services\Benchmark\BenchmarkRegressionDetector;
use App\Services\Notifications\AdminNotifier;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;

/**
 * Compares a finished scheduled benchmark run with its schedule's previous
 * comparable run, stores the comparison on the run and alerts admins when a
 * model regressed. A run whose comparison is stored is not checked again,
 * except by a recheck after re-judging changed its scores: that one replaces
 * the stored comparison and alerts only on regressions it did not hold.
 */
#[Timeout(60)]
#[Tries(3)]
final class CheckBenchmarkRegression implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $runId, public readonly bool $recheck = false) {}

    public function uniqueId(): string
    {
        return sprintf('benchmark-regression:%d%s', $this->runId, $this->recheck ? ':recheck' : '');
    }

    public function handle(BenchmarkRegressionDetector $benchmarkRegressionDetector, AdminNotifier $adminNotifier): void
    {
        $benchmarkRun = BenchmarkRun::query()->with('schedule')->find($this->runId);

        if (! $benchmarkRun instanceof BenchmarkRun || ($benchmarkRun->regressions !== null) !== $this->recheck) {
            return;
        }

        $known = $this->metricKeys($benchmarkRun->regressions ?? []);
        $detected = $benchmarkRegressionDetector->detect($benchmarkRun);

        $benchmarkRun->update(['baseline_run_id' => $detected['baseline_run_id'], 'regressions' => $detected['regressions']]);

        $fresh = array_values(array_filter(
            $detected['regressions'],
            fn (array $regression): bool => array_diff($this->metricKeys([$regression]), $known) !== [],
        ));

        if ($fresh !== [] && $benchmarkRun->schedule !== null) {
            $adminNotifier->send(ScheduledBenchmarkAlert::regression($benchmarkRun->schedule, $benchmarkRun, $fresh));
        }
    }

    /**
     * One key per regressed metric of a candidate.
     *
     * @param  list<array{candidate: array{provider: string, model: string, reasoning: string|null}, paired_cases: int, metrics: list<array{metric: string, before: float, after: float}>}>  $regressions
     * @return list<string>
     */
    private function metricKeys(array $regressions): array
    {
        $keys = [];

        foreach ($regressions as $regression) {
            foreach ($regression['metrics'] as $metric) {
                $keys[] = implode('|', [$regression['candidate']['provider'], $regression['candidate']['model'], $regression['candidate']['reasoning'] ?? 'provider_default', $metric['metric']]);
            }
        }

        return $keys;
    }
}
