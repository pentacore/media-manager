<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\ActionRequestStatus;
use App\Models\SubtitleUpload;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

final class PruneSubtitleUploads implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        SubtitleUpload::query()
            ->whereNull('cleaned_up_at')
            ->where(function (Builder $builder): void {
                $builder
                    ->where('expires_at', '<=', now())
                    ->orWhereNotNull('consumed_at')
                    ->orWhereNotNull('cancelled_at')
                    ->orWhereHas('actionRequest', function (Builder $builder): void {
                        $builder->where('status', ActionRequestStatus::Rejected);
                    });
            })
            ->chunkById(100, function ($uploads): void {
                foreach ($uploads as $upload) {
                    // Claim the row with the same lock the upload executor takes
                    // before reading the staged file. If the executor holds it,
                    // skip this cycle rather than deleting the file mid-write;
                    // a later prune cycle cleans it once the executor releases.
                    $lock = Cache::lock('subtitle-upload:'.$upload->id, 120);

                    if (! $lock->get()) {
                        continue;
                    }

                    try {
                        Storage::disk('local')->delete($upload->path);

                        SubtitleUpload::query()
                            ->whereKey($upload->id)
                            ->whereNull('cleaned_up_at')
                            ->update(['cleaned_up_at' => now()]);
                    } finally {
                        $lock->release();
                    }
                }
            });
    }
}
