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
use Illuminate\Contracts\Database\Query\Builder;
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
            ->filter(fn (MediaReplacementAttempt $mediaReplacementAttempt): bool => $this->startedBefore($mediaReplacementAttempt, $cutoff));

        if ($stuck->isEmpty()) {
            $this->info('No stuck media replacement attempts to reconcile.');

            return self::SUCCESS;
        }

        $admins = User::query()->where('role', UserRole::Admin)->get();
        $flagged = 0;
        $superseded = 0;

        foreach ($stuck as $attempt) {
            // Conditional transition: a concurrent Download webhook may have
            // moved this row to a terminal state (verified/needs_attention)
            // between selection and here. Only flag rows that are still
            // `downloading`, and only notify when this update actually changed
            // one — never regress a webhook result.
            //
            // The age predicate is repeated here rather than trusted from the
            // selection above, and it is what makes an operator Retry safe. A resume
            // rewrites started_at to now as its FIRST act but leaves the row
            // `downloading`, so status alone cannot tell a stalled attempt from one
            // that just restarted. Flagging a live run would regress its status and —
            // worse — restore monitoring below while it is mid-cleanup, so the
            // blocklist that follows would fire the arr's queued re-search against a
            // monitored target.
            //
            // What re-evaluating the age INSIDE the update can speak for: the read and
            // the write are one statement, so once the resume's started_at write has
            // committed this matches nothing at all. The selecting query's snapshot,
            // which cannot be retracted, is no longer what decides.
            //
            // What it cannot speak for — the reverse order, and it is the same residual
            // the repair pass discloses. For this update to match, it must commit
            // BEFORE the resume's started_at write (MediaReplacementActions::execute()).
            // So on every path where a row IS flagged here, the resume is still ahead of
            // us, and restoreSuspendedMonitoring() below can land after the resume
            // re-asserts its own suspension — leaving the target monitored when the
            // resume blocklists. The window is this update to that monitor PUT: one arr
            // round trip, and the harmful ordering additionally needs the resume to get
            // through its own unmonitor inside it. Closing it would need an atomic claim
            // on the row, and there is nothing safe to claim with — writing
            // monitoring_suspended = false before the PUT succeeds would destroy the
            // restore obligation itself. The resume's re-assert is the mitigation, not a
            // guarantee.
            $affected = MediaReplacementAttempt::query()
                ->whereKey($attempt->id)
                ->where('status', MediaReplacementStatus::Downloading->value)
                ->where(function (Builder $builder) use ($cutoff): void {
                    $builder->where('started_at', '<', $cutoff)
                        ->orWhere(function (Builder $builder) use ($cutoff): void {
                            $builder->whereNull('started_at')->where('created_at', '<', $cutoff);
                        });
                })
                ->update([
                    'status' => MediaReplacementStatus::NeedsAttention->value,
                    'failure_reason' => 'download_timeout',
                    'completed_at' => CarbonImmutable::now(),
                ]);

            if ($affected !== 1) {
                // Counted, never silent. Both clauses of the conditional update can be
                // what declined the row — a webhook terminalized it, or a resume moved
                // started_at back inside the cutoff — and the age clause in particular
                // must not be able to skip a selected row with nothing in the output.
                //
                // Reported as one figure because the two causes are genuinely
                // indistinguishable from here: the update reports a count, not a reason,
                // and re-reading the row to attribute one would be a second read of a
                // row that is by definition being changed underneath us — it could name
                // a cause that was not the one that actually declined the update. One
                // honest number with both causes named beats a precise-looking
                // attribution that can be wrong.
                $superseded++;

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

        // Same shape as the repair pass's reporting: the cause is named, and the whole
        // fragment is omitted at zero rather than printing "(0 skipped)" on every
        // ordinary run.
        $summary = sprintf('Flagged %d stuck media replacement attempt(s) as needs_attention', $flagged);

        $this->info($superseded === 0
            ? $summary.'.'
            : sprintf(
                '%s (skipped: %d settled or restarted between selection and update).',
                $summary,
                $superseded,
            ));

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
     * The `--hours` cutoff applies, reusing the one patience threshold this command
     * already has rather than inventing a second. It keeps the pass off a suspension
     * that has only just been taken out, and — because the resume path rewrites
     * started_at to now — off a row whose executor is live: a retried attempt is young
     * again by the same measure a fresh one is. That only holds if the age is measured
     * against a CURRENT read, so the loop below re-checks the full selection
     * predicate (terminal, suspended, old enough) on a fresh row immediately before
     * acting; the selecting query's snapshot is not evidence by the time we act on it.
     *
     * What none of that closes is the interval between one row's re-read and its own
     * monitor PUT. The resume path covers that from its own side by re-asserting the
     * suspension immediately before it blocklists, rather than trusting the flag this
     * pass clears.
     */
    private function restoreSettledMonitoring(CarbonImmutable $cutoff, MediaReplacementTracker $mediaReplacementTracker): void
    {
        $settled = MediaReplacementAttempt::query()
            ->where('monitoring_suspended', true)
            ->whereIn('status', MediaReplacementStatus::terminalValues())
            ->get()
            ->filter(fn (MediaReplacementAttempt $mediaReplacementAttempt): bool => $this->startedBefore($mediaReplacementAttempt, $cutoff));

        if ($settled->isEmpty()) {
            return;
        }

        $restored = 0;
        $claimed = 0;
        $alreadySettled = 0;
        $vanished = 0;

        foreach ($settled as $attempt) {
            // Re-read immediately before acting, with fresh() rather than refresh():
            // the row can be DELETED between the query above and here, because
            // MediaReplacementAttempt is MassPrunable and model:prune is scheduled, and
            // a long-settled attempt is simultaneously prunable and selectable here.
            // refresh() uses findOrFail, and that ModelNotFoundException would escape
            // the try below and abort handle() — skipping every remaining repair AND
            // the whole timeout pass.
            $fresh = $attempt->fresh();

            if (! $fresh instanceof MediaReplacementAttempt) {
                $vanished++;

                continue;
            }

            // A live run owns this row. An operator Retry reopens it to `downloading`
            // and/or rewrites started_at to now, and re-suspends monitoring for its own
            // resumed cleanup; restoring monitoring now would strip that run's
            // protection and let its blocklist trigger the competing auto-search.
            if (! $fresh->status->isTerminal() || ! $this->startedBefore($fresh, $cutoff)) {
                $claimed++;

                continue;
            }

            // Someone else discharged the obligation between the query and now — an
            // import event landing, or a concurrent invocation. Not a retry, and
            // reporting it as one would send an operator hunting for a run that never
            // happened.
            if ($fresh->monitoring_suspended !== true) {
                $alreadySettled++;

                continue;
            }

            // One attempt's failure must not abort the rest. restoreSuspendedMonitoring
            // already swallows arr-API errors and returns false, so this catch is for
            // the unexpected (a write failure); either way the suspension flag stays
            // set and the next scheduled run retries.
            try {
                $settledNow = $mediaReplacementTracker->restoreSuspendedMonitoring($fresh);
            } catch (Throwable $throwable) {
                $settledNow = false;

                Log::warning('Reconciliation could not restore monitoring on a settled media replacement attempt.', [
                    'attempt_id' => $fresh->id,
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
                'attempt_id' => $fresh->id,
                'status' => $fresh->status->value,
                'failure_reason' => $fresh->failure_reason,
            ]);
        }

        $summary = sprintf(
            'Restored monitoring on %d of %d settled media replacement attempt(s) whose suspension was never lifted',
            $restored,
            $settled->count(),
        );

        // Each cause named separately, and omitted entirely at zero — the previous
        // wording reported all three as "reopened" and printed "(0 skipped as
        // reopened)" on every ordinary run.
        $skipped = array_values(array_filter([
            $claimed > 0 ? sprintf('%d claimed by a live retry', $claimed) : null,
            $alreadySettled > 0 ? sprintf('%d already restored by another actor', $alreadySettled) : null,
            $vanished > 0 ? sprintf('%d pruned mid-pass', $vanished) : null,
        ]));

        $this->info($skipped === []
            ? $summary.'.'
            : sprintf('%s (skipped: %s).', $summary, implode('; ', $skipped)));
    }

    /**
     * Shared age basis for both passes and for the repair pass's re-read: an attempt
     * whose start (or creation, for rows predating started_at) precedes the cutoff.
     */
    private function startedBefore(MediaReplacementAttempt $mediaReplacementAttempt, CarbonImmutable $cutoff): bool
    {
        return ($mediaReplacementAttempt->started_at ?? $mediaReplacementAttempt->created_at)?->lessThan($cutoff) ?? false;
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
