<?php

declare(strict_types=1);

namespace App\Observers;

use App\Jobs\DeleteBenchmarkCaseFiles;
use App\Models\BenchmarkCase;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

/**
 * The attachment rows cascade in the database, so only the case's folder on
 * disk needs removing, which a queued job does.
 */
class BenchmarkCaseObserver implements ShouldHandleEventsAfterCommit
{
    public function deleted(BenchmarkCase $benchmarkCase): void
    {
        dispatch(new DeleteBenchmarkCaseFiles($benchmarkCase->id));
    }
}
