<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\BenchmarkCaseAttachment;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Support\Facades\Storage;

/**
 * Removes a deleted benchmark case's attachment folder. The attachment rows
 * cascade in the database, so only the files are left to clean up.
 */
#[Tries(3)]
#[Timeout(60)]
final class DeleteBenchmarkCaseFiles implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $benchmarkCaseId) {}

    public function handle(): void
    {
        Storage::disk(BenchmarkCaseAttachment::DISK)->deleteDirectory(BenchmarkCaseAttachment::directoryFor($this->benchmarkCaseId));
    }
}
