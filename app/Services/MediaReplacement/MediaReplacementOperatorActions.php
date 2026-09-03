<?php

declare(strict_types=1);

namespace App\Services\MediaReplacement;

use App\Enums\MediaReplacementStatus;
use App\Enums\ServiceType;
use App\Events\MediaReplacementAttemptChanged;
use App\Models\MediaReplacementAttempt;
use App\Models\ServiceConnection;
use App\Models\User;
use App\Services\Radarr\RadarrClient;
use App\Services\Sonarr\SonarrClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Operator-initiated transitions on a replacement attempt, driven from the
 * admin attempts pages. Every method guards its own preconditions with a
 * conditional write so a stale page (or a concurrent webhook) cannot clobber
 * a result that landed first, and reports the outcome as an
 * OperatorActionResult the controller only has to turn into a toast.
 */
final readonly class MediaReplacementOperatorActions
{
    public const string CANCELLED_BY_OPERATOR = 'cancelled_by_operator';

    private const int QUEUE_PAGE_SIZE = 200;

    public function __construct(private MediaReplacementTracker $mediaReplacementTracker) {}

    /**
     * Records that a human has looked at a needs_attention attempt. Touches
     * neither status nor failure_reason: a late import on a recoverable reason
     * must still be able to resolve the attempt through the tracker.
     */
    public function acknowledge(MediaReplacementAttempt $mediaReplacementAttempt, User $user): OperatorActionResult
    {
        $updated = MediaReplacementAttempt::query()
            ->whereKey($mediaReplacementAttempt->id)
            ->where('status', MediaReplacementStatus::NeedsAttention->value)
            ->whereNull('acknowledged_at')
            ->update([
                'acknowledged_at' => now(),
                'acknowledged_by' => $user->id,
            ]);

        if ($updated !== 1) {
            return new OperatorActionResult(false, __('This attempt is already acknowledged or no longer needs attention.'));
        }

        $mediaReplacementAttempt->refresh();
        event(new MediaReplacementAttemptChanged($mediaReplacementAttempt));

        return new OperatorActionResult(true, __('Attempt acknowledged.'));
    }

    /**
     * Lifts a monitoring suspension the pipeline left behind on a settled
     * attempt, instead of waiting for the scheduled reconcile pass.
     */
    public function restoreMonitoring(MediaReplacementAttempt $mediaReplacementAttempt): OperatorActionResult
    {
        if (! $mediaReplacementAttempt->status->isTerminal() || $mediaReplacementAttempt->monitoring_suspended !== true) {
            return new OperatorActionResult(false, __('Monitoring is not suspended on this attempt.'));
        }

        if (! $this->mediaReplacementTracker->restoreSuspendedMonitoring($mediaReplacementAttempt)) {
            return new OperatorActionResult(false, __('The service refused to restore monitoring. Check the connection and try again.'));
        }

        $mediaReplacementAttempt->refresh();
        event(new MediaReplacementAttemptChanged($mediaReplacementAttempt));

        return new OperatorActionResult(true, __('Monitoring restored.'));
    }

    /**
     * Abandons an in-flight attempt: removes our download from the arr queue
     * (no blocklist — the operator is giving up on this attempt, not vetoing
     * the release), restores monitoring, and fails the attempt. Runs under the
     * execution lock so it never interleaves with the executor, and the final
     * transition is conditional so a webhook that settled the row first wins.
     */
    public function cancel(MediaReplacementAttempt $mediaReplacementAttempt): OperatorActionResult
    {
        if (! $this->isInFlight($mediaReplacementAttempt)) {
            return new OperatorActionResult(false, __('Only in-flight attempts can be cancelled.'));
        }

        $lock = Cache::lock(
            MediaReplacementExecutionLock::key($mediaReplacementAttempt->action_request_id),
            MediaReplacementExecutionLock::TTL_SECONDS,
        );

        if (! $lock->get()) {
            return new OperatorActionResult(false, __('The executor is still working on this attempt. Try again in a moment.'));
        }

        try {
            $mediaReplacementAttempt->refresh();

            if (! $this->isInFlight($mediaReplacementAttempt)) {
                return new OperatorActionResult(false, __('This attempt has already settled.'));
            }

            $warnings = [];

            if (! $this->removeOwnDownload($mediaReplacementAttempt)) {
                $warnings[] = __('the download could not be removed from the service');
            }

            if (! $this->mediaReplacementTracker->restoreSuspendedMonitoring($mediaReplacementAttempt)) {
                $warnings[] = __('monitoring could not be restored');
            }

            $won = MediaReplacementAttempt::query()
                ->whereKey($mediaReplacementAttempt->id)
                ->whereNotIn('status', MediaReplacementStatus::terminalValues())
                ->update([
                    'status' => MediaReplacementStatus::Failed->value,
                    'failure_reason' => self::CANCELLED_BY_OPERATOR,
                    'completed_at' => now(),
                ]) === 1;

            if (! $won) {
                return new OperatorActionResult(false, __('The attempt settled before it could be cancelled.'));
            }

            $mediaReplacementAttempt->refresh();
            event(new MediaReplacementAttemptChanged($mediaReplacementAttempt));

            if ($warnings !== []) {
                return new OperatorActionResult(true, __('Attempt cancelled, but :warnings.', ['warnings' => implode(' and ', $warnings)]));
            }

            return new OperatorActionResult(true, __('Attempt cancelled.'));
        } finally {
            $lock->release();
        }
    }

    private function isInFlight(MediaReplacementAttempt $mediaReplacementAttempt): bool
    {
        return in_array($mediaReplacementAttempt->status, [MediaReplacementStatus::Requested, MediaReplacementStatus::Downloading], true);
    }

    /**
     * Removes the queue row carrying our download id. True when there was
     * nothing to remove or the removal succeeded; false only when the arr
     * refused, so the caller can finish cancelling and report the leftover.
     */
    private function removeOwnDownload(MediaReplacementAttempt $mediaReplacementAttempt): bool
    {
        $downloadId = is_string($mediaReplacementAttempt->download_id) ? trim($mediaReplacementAttempt->download_id) : '';
        $serviceConnection = $mediaReplacementAttempt->serviceConnection;

        if ($downloadId === '' || ! $serviceConnection instanceof ServiceConnection) {
            return true;
        }

        $client = $serviceConnection->type === ServiceType::Radarr
            ? new RadarrClient($serviceConnection)
            : new SonarrClient($serviceConnection);

        try {
            foreach ($this->queueRecords($client) as $record) {
                $recordDownloadId = is_scalar($record['downloadId'] ?? null) ? trim((string) $record['downloadId']) : '';
                $queueItemId = is_int($record['id'] ?? null) && $record['id'] > 0 ? $record['id'] : null;

                if ($queueItemId === null || strcasecmp($recordDownloadId, $downloadId) !== 0) {
                    continue;
                }

                // removeFromClient evicts the download itself, which settles every
                // queue row sharing this id (a season pack has one per episode).
                $client->removeQueueItem($queueItemId, removeFromClient: true, blocklist: false, skipRedownload: true);

                break;
            }

            return true;
        } catch (RequestException|ConnectionException $exception) {
            Log::warning('Operator cancel could not remove the replacement download from the arr queue.', [
                'attempt_id' => $mediaReplacementAttempt->id,
                'service_connection_id' => $serviceConnection->id,
                'exception' => $exception::class,
            ]);

            return false;
        }
    }

    /**
     * Every queue page, materialised before any deletion.
     *
     * @return list<array<string, mixed>>
     *
     * @throws RequestException|ConnectionException
     */
    private function queueRecords(SonarrClient|RadarrClient $client): array
    {
        $page = 1;
        $records = [];

        do {
            $queue = $client->getQueue(['page' => $page, 'pageSize' => self::QUEUE_PAGE_SIZE]);
            $pageRecords = is_array($queue['records'] ?? null)
                ? array_values(array_filter($queue['records'], is_array(...)))
                : [];
            $records = [...$records, ...$pageRecords];
            $total = is_int($queue['totalRecords'] ?? null) ? $queue['totalRecords'] : null;
            $page++;
        } while (count($pageRecords) === self::QUEUE_PAGE_SIZE && ($total === null || count($records) < $total));

        return $records;
    }
}
