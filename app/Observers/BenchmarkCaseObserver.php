<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\BenchmarkCase;
use App\Models\BenchmarkCaseAttachment;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Support\Facades\Storage;

/**
 * The attachment rows cascade in the database, so only the case's folder on
 * disk needs removing.
 */
class BenchmarkCaseObserver implements ShouldHandleEventsAfterCommit
{
    public function deleted(BenchmarkCase $benchmarkCase): void
    {
        Storage::disk(BenchmarkCaseAttachment::DISK)->deleteDirectory(BenchmarkCaseAttachment::directoryFor($benchmarkCase->id));
    }
}
