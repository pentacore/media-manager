<?php

declare(strict_types=1);

namespace App\Jobs;

use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use App\Services\Bazarr\SubtitleCaseReconciler;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

#[Timeout(60)]
#[Tries(3)]
final class ReconcileSubtitleCase implements ShouldQueue
{
    use Queueable;

    /** @param array<string, mixed> $candidate */
    public function __construct(public array $candidate) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function handle(SubtitleCaseReconciler $subtitleCaseReconciler): void
    {
        $subtitleCaseReconciler->reconcile($this->candidate);
    }

    public function failed(?Throwable $throwable): void
    {
        Log::error('Subtitle case reconciliation failed.', [
            'bazarr_connection_id' => $this->candidate['bazarr_connection_id'] ?? null,
            'service_connection_id' => $this->candidate['service_connection_id'] ?? null,
            'exception' => $throwable instanceof Throwable ? $throwable::class : null,
        ]);
    }
}
