<?php

declare(strict_types=1);

namespace App\Jobs;

use Illuminate\Contracts\Database\Query\Builder;
use App\Enums\ActionRequestStatus;
use App\Models\SubtitleUpload;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
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
                    Storage::disk('local')->delete($upload->path);

                    SubtitleUpload::query()
                        ->whereKey($upload->id)
                        ->whereNull('cleaned_up_at')
                        ->update(['cleaned_up_at' => now()]);
                }
            });
    }
}
