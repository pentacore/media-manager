<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\BenchmarkRunStatus;
use App\Models\BenchmarkRun;
use App\Services\Benchmark\BenchmarkRunSettler;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

#[Description('Fail benchmark results stuck running past the stale window and settle their runs. A lost queue payload leaves a result running forever; no job is left to settle its run.')]
#[Signature('benchmark:settle-stalled')]
/**
 * Safe only while RunBenchmarkCase's #[Timeout(280)] stays below
 * BenchmarkResult::CLAIM_STALE_AFTER_SECONDS: a live job never looks stalled.
 */
class SettleStalledBenchmarkRuns extends Command
{
    public function handle(BenchmarkRunSettler $benchmarkRunSettler): int
    {
        $runIds = BenchmarkRun::query()
            ->whereIn('status', [BenchmarkRunStatus::Pending, BenchmarkRunStatus::Running])
            ->whereHas('results', fn (Builder $builder): Builder => $builder->runStale())
            ->pluck('id');

        foreach ($runIds as $runId) {
            $benchmarkRunSettler->settle($runId, failStalled: true);
        }

        $this->info(sprintf('Settled %d benchmark run(s) with stalled results.', $runIds->count()));

        return self::SUCCESS;
    }
}
