<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\ActionRequestStatus;
use App\Enums\QueueLane;
use App\Events\ActionRequestStatusChanged;
use App\Models\ActionRequest;
use App\Services\Actions\ActionExecutor;
use App\Services\Actions\ActionRequestActivityLogger;
use App\Services\Arr\ArrActions;
use App\Services\Arr\ManualImportActions;
use App\Services\Arr\RemoveStuckDownloadActions;
use App\Services\Bazarr\BazarrActions;
use App\Services\Bazarr\BazarrIndeterminateOutcomeException;
use App\Services\Emby\EmbyActions;
use App\Services\MediaReplacement\MediaReplacementActions;
use App\Services\Radarr\RadarrActions;
use App\Services\Seerr\SeerrActions;
use App\Services\Sonarr\SonarrActions;
use App\Services\Whisparr\WhisparrActions;
use App\Support\UpstreamErrorText;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Queue\Attributes\Queue;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\UniqueFor;
use Illuminate\Support\Facades\Log;
use Throwable;

#[Queue(QueueLane::Actions)]
#[Timeout(270)]
#[UniqueFor(3600)]
class ExecuteActionRequest implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * Deleting the underlying model while this job is queued must drop the
     * job silently instead of filling failed_jobs with
     * ModelNotFoundException noise.
     */
    public bool $deleteWhenMissingModels = true;

    public int $tries = 3;

    public int $backoff = 30;

    /**
     * Every action type the queue can execute, mapped to its executor.
     *
     * This is the single source of truth for "known action types": the
     * ActionTypeConfig seeder test asserts that every key here is seeded and
     * every seeded type appears here, so a type added to one side without the
     * other fails CI instead of silently no-op'ing at run time.
     *
     * @var array<string, class-string<ActionExecutor>>
     */
    public const array EXECUTORS = [
        'delete_series' => SonarrActions::class,
        'add_series' => SonarrActions::class,
        'monitor_series' => SonarrActions::class,
        'set_series_quality_profile' => SonarrActions::class,
        'monitor_episodes' => SonarrActions::class,
        'search_media' => ArrActions::class,
        'grab_release' => ArrActions::class,
        'delete_movie' => RadarrActions::class,
        'add_movie' => RadarrActions::class,
        'monitor_movie' => RadarrActions::class,
        'set_movie_quality_profile' => RadarrActions::class,
        'whisparr_add_item' => WhisparrActions::class,
        'whisparr_delete_item' => WhisparrActions::class,
        'whisparr_monitor_item' => WhisparrActions::class,
        'whisparr_set_quality_profile' => WhisparrActions::class,
        'whisparr_search' => WhisparrActions::class,
        'cleanup_seerr_request' => SeerrActions::class,
        'approve_seerr_request' => SeerrActions::class,
        'decline_seerr_request' => SeerrActions::class,
        'emby_library_scan' => EmbyActions::class,
        'resolve_manual_import' => ManualImportActions::class,
        'remove_stuck_download' => RemoveStuckDownloadActions::class,
        'replace_media_file' => MediaReplacementActions::class,
        'bazarr_download_best' => BazarrActions::class,
        'bazarr_download_exact' => BazarrActions::class,
        'bazarr_upload_subtitle' => BazarrActions::class,
        'bazarr_delete_subtitle' => BazarrActions::class,
        'bazarr_sync_subtitle' => BazarrActions::class,
        'bazarr_translate_subtitle' => BazarrActions::class,
        'bazarr_modify_subtitle' => BazarrActions::class,
        'bazarr_scan_media' => BazarrActions::class,
        'bazarr_run_task' => BazarrActions::class,
    ];

    /**
     * Types whose executor is safe to enter again after a worker died
     * mid-run: an Emby library refresh is idempotent. replace_media_file is
     * deliberately NOT here even though MediaReplacementActions resumes from
     * durable MediaReplacementAttempt checkpoints: its execution lock
     * (Cache::lock, 900s) outlives a dead worker, so the re-delivery arrives
     * while the lease is still held and LockTimeoutException surfaces as an
     * ordinary execution_failed instead of actually resuming. It still fails
     * safely — no grab is duplicated — but as needs_reconciliation like every
     * other non-idempotent type; a later manual retry resumes through
     * runReplacement()'s checkpoints once the lease has expired.
     *
     * @var list<string>
     */
    private const array RESUMABLE_TYPES = ['emby_library_scan'];

    /**
     * Fixed text for the needs_reconciliation / worker_lost result written
     * both when a re-delivery finds its request Executing with no retry
     * marker (resumeRedelivery()) and when the worker running the final
     * attempt is lost outright, so the queue calls failed() directly without
     * ever re-entering handle() (failed()). Either way the upstream call may
     * already have landed with no recorded outcome.
     */
    private const string WORKER_LOST_MESSAGE = 'The worker running this action stopped before recording an outcome, so the change may already have reached the target service. Check it there before retrying.';

    public function __construct(public ActionRequest $actionRequest) {}

    public function handle(): void
    {
        if (! $this->claimForExecution()) {
            return;
        }

        $executor = $this->resolveExecutor($this->actionRequest->type);

        if (! $executor instanceof ActionExecutor) {
            $this->markFailed([
                'reason' => 'no_executor',
                'message' => sprintf('No executor registered for type "%s"', $this->actionRequest->type),
            ]);

            return;
        }

        try {
            $result = $executor->execute($this->actionRequest);
        } catch (BazarrIndeterminateOutcomeException $indeterminate) {
            $this->markFailed([
                'reason' => 'needs_reconciliation',
                'message' => $indeterminate->getMessage(),
                'indeterminate' => true,
            ]);

            return;
        } catch (ConnectionException|RequestException $exception) {
            // Only a deterministic 4xx is permanent (deleting an
            // already-removed series will 404 on every attempt) — retrying
            // it three times with backoff just delayed the Failed state and
            // mislabeled it retries_exhausted. Everything else retries:
            // connection loss, upstream 5xx, and a 200 that isn't usable
            // data (ArrUnexpectedResponse/SabnzbdRefused) — the same policy
            // as BaseArrController::upstreamFailureMessage(), which treats
            // anything short of a clientError() as an outage, not a refusal.
            $transient = ! $exception instanceof RequestException || ! $exception->response->clientError();

            if (! $transient) {
                $this->markFailed([
                    'reason' => 'execution_failed',
                    'message' => $exception->getMessage(),
                    'exception' => $exception::class,
                ]);

                return;
            }

            // Transient failure: rethrow so Laravel retries per $tries + $backoff.
            // On the final attempt, persist Failed state and return without throwing
            // (preventing double-handling in failed()).
            if ($this->attempts() >= $this->tries) {
                $this->markFailed([
                    'reason' => 'retries_exhausted',
                    'message' => $exception->getMessage(),
                    'exception' => $exception::class,
                ]);

                return;
            }

            // Tell the re-delivery that this attempt gave up on purpose. Without
            // the marker, a re-delivery of an Executing request means the
            // worker died mid-execute (see claimForExecution()).
            $this->actionRequest->update([
                'result' => ['retry_scheduled' => true, 'attempt' => $this->attempts()],
            ]);

            throw $exception;
        } catch (Throwable $permanent) {
            // Permanent failure: mark Failed immediately — no retry.
            $this->markFailed([
                'reason' => 'execution_failed',
                'message' => $permanent->getMessage(),
                'exception' => $permanent::class,
                'indeterminate' => false,
            ]);

            return;
        }

        $this->actionRequest->update([
            'status' => ActionRequestStatus::Completed,
            'result' => ['success' => true, ...$result],
        ]);
        event(new ActionRequestStatusChanged($this->actionRequest));
    }

    public function uniqueId(): string
    {
        return (string) $this->actionRequest->id;
    }

    public function failed(?Throwable $throwable): void
    {
        // Called by Laravel when retries are exhausted via a rethrown exception,
        // or when the worker running the final attempt was lost outright (killed,
        // OOM'd, or timed out) with no further attempt left to re-deliver it.
        // Only a request this job still owns — Approved, or Executing — is
        // failed here. A terminal row is left alone: the worker can die after
        // Completed committed but before the ack, and turning that into
        // job_failed would invite a manual retry of a change that landed.
        $this->actionRequest->refresh();
        if (! in_array($this->actionRequest->status, [ActionRequestStatus::Approved, ActionRequestStatus::Executing], true)) {
            return;
        }

        if ($this->actionRequest->status === ActionRequestStatus::Executing && ! $this->retryWasScheduled()) {
            $exceptionClass = $throwable instanceof Throwable ? $throwable::class : null;

            Log::warning('ExecuteActionRequest: worker lost on the final attempt; failing instead of running the action again', [
                'action_request_id' => $this->actionRequest->id,
                'type' => $this->actionRequest->type,
                'exception' => $exceptionClass,
            ]);

            $this->markFailed([
                'reason' => 'needs_reconciliation',
                'message' => self::WORKER_LOST_MESSAGE,
                'indeterminate' => true,
                'worker_lost' => true,
                'exception' => $exceptionClass,
            ]);

            return;
        }

        $this->markFailed([
            'reason' => 'job_failed',
            'message' => $throwable?->getMessage() ?? 'Job failed',
        ]);
    }

    /**
     * Executor exception text embeds upstream response bodies
     * (RequestException) and file paths, and result.message is shown on the
     * Action Queue — so it is stored reduced, never raw.
     *
     * @param  array<string, mixed>  $result
     */
    private function markFailed(array $result): void
    {
        if (is_string($result['message'] ?? null)) {
            $result['message'] = UpstreamErrorText::sanitize($result['message']);
        }

        $this->actionRequest->update([
            'status' => ActionRequestStatus::Failed,
            'result' => ['success' => false, ...$result],
        ]);
        event(new ActionRequestStatusChanged($this->actionRequest));
    }

    private function claimForExecution(): bool
    {
        $claimed = ActionRequest::query()
            ->whereKey($this->actionRequest->id)
            ->where('status', ActionRequestStatus::Approved->value)
            ->update(['status' => ActionRequestStatus::Executing]);

        $this->actionRequest->refresh();

        if ($claimed === 1) {
            // The conditional update bypasses ActionRequestObserver; record
            // the Executing transition in the audit trail explicitly.
            resolve(ActionRequestActivityLogger::class)->statusChanged($this->actionRequest);
            event(new ActionRequestStatusChanged($this->actionRequest));

            return true;
        }

        if ($this->actionRequest->status === ActionRequestStatus::Executing && $this->attempts() > 1) {
            return $this->resumeRedelivery();
        }

        Log::info('ExecuteActionRequest: skipping — not approved', [
            'action_request_id' => $this->actionRequest->id,
            'status' => $this->actionRequest->status->value,
        ]);

        return false;
    }

    /**
     * A re-delivery of a request that is already Executing. A deliberate
     * retry (handle() rethrew a transient failure) left the retry marker;
     * without it the previous worker died mid-execute (SIGKILL, OOM, the job
     * timeout) after the upstream call may already have landed.
     */
    private function resumeRedelivery(): bool
    {
        if ($this->retryWasScheduled()) {
            // Consume the marker before running again: if this attempt's
            // worker dies too, the next re-delivery must not find it.
            $this->actionRequest->update(['result' => null]);

            return true;
        }

        if (in_array($this->actionRequest->type, self::RESUMABLE_TYPES, true)) {
            return true;
        }

        Log::warning('ExecuteActionRequest: worker lost mid-execution; failing instead of running the action again', [
            'action_request_id' => $this->actionRequest->id,
            'type' => $this->actionRequest->type,
            'attempt' => $this->attempts(),
        ]);

        $this->markFailed([
            'reason' => 'needs_reconciliation',
            'message' => self::WORKER_LOST_MESSAGE,
            'indeterminate' => true,
            'worker_lost' => true,
        ]);

        return false;
    }

    private function retryWasScheduled(): bool
    {
        return ($this->actionRequest->result['retry_scheduled'] ?? false) === true;
    }

    private function resolveExecutor(string $type): ?ActionExecutor
    {
        $class = self::EXECUTORS[$type] ?? null;

        if ($class === null) {
            return null;
        }

        if (! class_exists($class) && ! app()->bound($class)) {
            return null;
        }

        return resolve($class);
    }
}
