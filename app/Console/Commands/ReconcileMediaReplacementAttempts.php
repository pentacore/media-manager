<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\MediaReplacementStatus;
use App\Enums\UserRole;
use App\Models\MediaReplacementAttempt;
use App\Models\User;
use App\Notifications\MediaReplacementStatusChanged;
use App\Services\MediaReplacement\MediaReplacementTracker;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

#[Description('Flag media replacement attempts stuck in downloading past a timeout as needs_attention, so a stalled or silently-failed download does not leave the reviewed file deleted with no notification. Also restores monitoring on settled attempts whose suspension was never lifted.')]
#[Signature('media-replacement:reconcile {--hours=6 : Flag downloading attempts whose download started more than this many hours ago, and repair monitoring on settled attempts at least this old}')]
class ReconcileMediaReplacementAttempts extends Command
{
    public function handle(): int
    {
        $hours = max(1, (int) $this->option('hours'));
        $cutoff = CarbonImmutable::now()->subHours($hours);

        $mediaReplacementTracker = resolve(MediaReplacementTracker::class);

        // Repair BEFORE flagging. The flagging pass below performs its own
        // restore for each attempt it terminalizes, so running the repair
        // afterwards would immediately retry a restore that just failed against
        // the same service — twice the arr traffic for no better odds. A failure
        // there waits for the next scheduled run instead.
        $this->restoreSettledMonitoring($cutoff, $mediaReplacementTracker);

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
     * Lift a monitoring suspension that outlived the attempt that created it.
     *
     * An attempt can reach a terminal state without anyone restoring monitoring:
     * a webhook terminalizes it while the executor is still cleaning up (so the
     * executor's finalizeAfterCleanup no-ops on the already-terminal row), or the
     * old file's deletion fails and the executor throws before it ever gets
     * there. The normal restore is owned by the import event, and for a settled
     * attempt no import is coming — so without this the target stays unmonitored
     * forever and silently stops receiving upgrades, the exact outcome this
     * command exists to prevent.
     *
     * Deliberately NOT part of the timeout flagging: these attempts reached their
     * terminal state legitimately and the operator was already notified when they
     * did. This pass settles monitoring and nothing else — no status, no
     * failure_reason, no completed_at, no notification.
     *
     * The `--hours` cutoff applies. An attempt younger than it may still have a
     * live executor mid-cleanup, and remonitoring the target between that
     * executor's delete and its blocklist would re-open the competing-grab race
     * the suspension exists to close. No executor run outlives the cutoff, so
     * honouring it makes that interleaving impossible rather than merely
     * unlikely, at the cost of a bounded delay in a repair that is otherwise
     * indefinitely overdue.
     */
    private function restoreSettledMonitoring(CarbonImmutable $cutoff, MediaReplacementTracker $mediaReplacementTracker): void
    {
        $settled = MediaReplacementAttempt::query()
            ->where('monitoring_suspended', true)
            ->whereIn('status', MediaReplacementStatus::terminalValues())
            ->get()
            ->filter(fn (MediaReplacementAttempt $mediaReplacementAttempt): bool => ($mediaReplacementAttempt->started_at ?? $mediaReplacementAttempt->created_at)?->lessThan($cutoff) ?? false);

        if ($settled->isEmpty()) {
            return;
        }

        $restored = 0;

        foreach ($settled as $attempt) {
            // One attempt's failure must not abort the rest. restoreSuspendedMonitoring
            // already swallows arr-API errors and returns false, so this catch is for
            // the unexpected (a write failure); either way the suspension flag stays
            // set and the next scheduled run retries.
            try {
                $settledNow = $mediaReplacementTracker->restoreSuspendedMonitoring($attempt);
            } catch (Throwable $throwable) {
                $settledNow = false;

                Log::warning('Reconciliation could not restore monitoring on a settled media replacement attempt.', [
                    'attempt_id' => $attempt->id,
                    'exception' => $throwable::class,
                ]);
            }

            if ($settledNow) {
                $restored++;

                continue;
            }

            // Logged rather than notified: this command runs hourly, so an arr that
            // stays unreachable would notify every hour for the same attempt, and
            // there is no durable "already told you" marker to suppress it with.
            // Every attempt this pass can touch is already failed/needs_attention and
            // was notified when it got there, and monitoring_suspended remains as the
            // durable record that monitoring is still off.
            Log::warning('Monitoring is still suspended on a settled media replacement attempt; will retry next run.', [
                'attempt_id' => $attempt->id,
                'status' => $attempt->status->value,
                'failure_reason' => $attempt->failure_reason,
            ]);
        }

        $this->info(sprintf(
            'Restored monitoring on %d of %d settled media replacement attempt(s) whose suspension was never lifted.',
            $restored,
            $settled->count(),
        ));
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
