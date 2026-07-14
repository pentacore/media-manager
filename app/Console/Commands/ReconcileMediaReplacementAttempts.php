<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\MediaReplacementStatus;
use App\Enums\UserRole;
use App\Models\MediaReplacementAttempt;
use App\Models\User;
use App\Notifications\MediaReplacementStatusChanged;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;

#[Description('Flag media replacement attempts stuck in downloading past a timeout as needs_attention, so a stalled or silently-failed download does not leave the reviewed file deleted with no notification.')]
#[Signature('media-replacement:reconcile {--hours=6 : Flag downloading attempts whose download started more than this many hours ago}')]
class ReconcileMediaReplacementAttempts extends Command
{
    public function handle(): int
    {
        $hours = max(1, (int) $this->option('hours'));
        $cutoff = CarbonImmutable::now()->subHours($hours);

        $stuck = MediaReplacementAttempt::query()
            ->where('status', MediaReplacementStatus::Downloading->value)
            ->get()
            ->filter(fn (MediaReplacementAttempt $attempt): bool => ($attempt->started_at ?? $attempt->created_at)?->lessThan($cutoff) ?? false);

        if ($stuck->isEmpty()) {
            $this->info('No stuck media replacement attempts to reconcile.');

            return self::SUCCESS;
        }

        $admins = User::query()->where('role', UserRole::Admin)->get();

        foreach ($stuck as $attempt) {
            $attempt->update([
                'status' => MediaReplacementStatus::NeedsAttention,
                'failure_reason' => 'download_timeout',
                'completed_at' => CarbonImmutable::now(),
            ]);

            if ($admins->isNotEmpty()) {
                $title = (string) ($attempt->candidate['title'] ?? 'Media replacement');

                Notification::send($admins, new MediaReplacementStatusChanged(
                    service: (string) ($attempt->target['service'] ?? ''),
                    title: $title,
                    message: sprintf('Replacement download stalled for over %d hour(s) and needs manual review; the old file was already removed.', $hours),
                    level: 'warning',
                ));
            }
        }

        $this->info(sprintf('Flagged %d stuck media replacement attempt(s) as needs_attention.', $stuck->count()));

        return self::SUCCESS;
    }
}
