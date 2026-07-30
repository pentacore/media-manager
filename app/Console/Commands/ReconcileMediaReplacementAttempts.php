<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\MediaReplacementStatus;
use App\Enums\UserRole;
use App\Events\MediaReplacementAttemptChanged;
use App\Models\MediaReplacementAttempt;
use App\Models\User;
use App\Notifications\MediaReplacementStatusChanged;
use App\Services\MediaReplacement\MediaReplacementTracker;
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
            ->filter(fn (MediaReplacementAttempt $mediaReplacementAttempt): bool => ($mediaReplacementAttempt->started_at ?? $mediaReplacementAttempt->created_at)?->lessThan($cutoff) ?? false);

        if ($stuck->isEmpty()) {
            $this->info('No stuck media replacement attempts to reconcile.');

            return self::SUCCESS;
        }

        $admins = User::query()->where('role', UserRole::Admin)->get();
        $flagged = 0;

        $mediaReplacementTracker = resolve(MediaReplacementTracker::class);

        foreach ($stuck as $attempt) {
            // Conditional transition: a concurrent Download webhook may have
            // moved this row to a terminal state (verified/needs_attention)
            // between selection and here. Only flag rows that are still
            // `downloading`, and only notify when this update actually changed
            // one — never regress a webhook result.
            $affected = MediaReplacementAttempt::query()
                ->whereKey($attempt->id)
                ->where('status', MediaReplacementStatus::Downloading->value)
                ->update([
                    'status' => MediaReplacementStatus::NeedsAttention->value,
                    'failure_reason' => 'download_timeout',
                    'completed_at' => CarbonImmutable::now(),
                ]);

            if ($affected !== 1) {
                continue;
            }

            $flagged++;
            $attempt->refresh();

            // Announce the terminal state this sweep won so a correlated subtitle
            // case can move to needs_review.
            event(new MediaReplacementAttemptChanged($attempt));

            // The sweep is the last actor that will ever touch this attempt on
            // several paths (indeterminate grab, executor died mid-cleanup,
            // lost Grab webhook) — if the executor suspended monitoring, no
            // webhook is coming to restore it, so restore it here or the
            // target silently stops getting upgrades forever.
            $monitoringRestored = $mediaReplacementTracker->restoreSuspendedMonitoring($attempt);

            if ($admins->isNotEmpty()) {
                Notification::send($admins, new MediaReplacementStatusChanged(
                    service: (string) ($attempt->target['service'] ?? ''),
                    title: (string) ($attempt->candidate['title'] ?? 'Media replacement'),
                    message: $this->timeoutMessage($attempt, $hours, $monitoringRestored),
                    level: 'warning',
                ));
            }
        }

        $this->info(sprintf('Flagged %d stuck media replacement attempt(s) as needs_attention.', $flagged));

        return self::SUCCESS;
    }

    /**
     * Derive the operator message from durable state instead of asserting
     * "the old file was already removed" — false on the indeterminate-grab
     * and died-before-cleanup paths, where nothing was ever deleted.
     */
    private function timeoutMessage(MediaReplacementAttempt $mediaReplacementAttempt, int $hours, bool $monitoringRestored): string
    {
        $fileState = match (true) {
            $mediaReplacementAttempt->cleanup_completed_at !== null => 'the old file was removed',
            $mediaReplacementAttempt->grab_accepted_at !== null => 'the replacement was grabbed but the old file may still be present',
            default => 'no grab was confirmed, so the old file was not removed',
        };

        $monitoring = $monitoringRestored ? '' : ' Monitoring could not be restored and is still disabled on the target.';

        return sprintf(
            'Replacement download stalled for over %d hour(s) and needs manual review; %s.%s',
            $hours,
            $fileState,
            $monitoring,
        );
    }
}
