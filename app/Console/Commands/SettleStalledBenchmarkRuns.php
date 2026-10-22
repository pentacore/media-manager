<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\BenchmarkRun;
use App\Services\Benchmark\BenchmarkNeutralRejudgeLauncher;
use App\Services\Benchmark\BenchmarkRunSettler;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/**
 * Sweeps every run with a stale result, ended or not: settle() fails a
 * stale result in an ended run as stopped and in a live one as stalled.
 *
 * Safe only while RunBenchmarkCase's #[Timeout(280)] stays below
 * BenchmarkResult::CLAIM_STALE_AFTER_SECONDS: a live job never looks stalled.
 */
#[Description('Fail benchmark results stuck running past the stale window and settle their runs. A lost queue payload leaves a result running forever; no job is left to settle its run. Also fails neutral judgements stuck past the stale window.')]
#[Signature('benchmark:settle-stalled')]
class SettleStalledBenchmarkRuns extends Command
{
    public function handle(BenchmarkRunSettler $benchmarkRunSettler, BenchmarkNeutralRejudgeLauncher $benchmarkNeutralRejudgeLauncher): int
    {
        $runIds = BenchmarkRun::query()
            ->whereHas('results', fn (Builder $builder): Builder => $builder->runStale())
            ->pluck('id');

        foreach ($runIds as $runId) {
            $benchmarkRunSettler->settle($runId, failStalled: true);
        }

        $judgements = $benchmarkNeutralRejudgeLauncher->sweepStalled();

        $this->info(sprintf('Settled %d benchmark run(s) with stalled results and failed %d stalled neutral judgement(s).', $runIds->count(), $judgements));

        return self::SUCCESS;
    }
}
