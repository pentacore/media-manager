<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\ActionRequestStatus;
use App\Events\ActionRequestStatusChanged;
use App\Models\ActionRequest;
use App\Services\Actions\ActionExecutor;
use App\Services\Emby\EmbyActions;
use App\Services\Radarr\RadarrActions;
use App\Services\Seerr\SeerrActions;
use App\Services\Sonarr\SonarrActions;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class ExecuteActionRequest implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $backoff = 30;

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
        } catch (ConnectionException|RequestException $transient) {
            // Transient failure: rethrow so Laravel retries per $tries + $backoff.
            // On the final attempt, persist Failed state and return without throwing
            // (preventing double-handling in failed()).
            if ($this->attempts() >= $this->tries) {
                $this->markFailed([
                    'reason' => 'retries_exhausted',
                    'message' => $transient->getMessage(),
                    'exception' => $transient::class,
                ]);

                return;
            }

            throw $transient;
        } catch (Throwable $permanent) {
            // Permanent failure: mark Failed immediately — no retry.
            $this->markFailed([
                'reason' => 'execution_failed',
                'message' => $permanent->getMessage(),
                'exception' => $permanent::class,
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
        // Called by Laravel when retries are exhausted via a rethrown exception.
        // If handle() already persisted Failed state, short-circuit.
        $this->actionRequest->refresh();
        if ($this->actionRequest->status === ActionRequestStatus::Failed) {
            return;
        }

        $this->markFailed([
            'reason' => 'job_failed',
            'message' => $throwable?->getMessage() ?? 'Job failed',
        ]);
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function markFailed(array $result): void
    {
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
            event(new ActionRequestStatusChanged($this->actionRequest));

            return true;
        }

        if ($this->actionRequest->status === ActionRequestStatus::Executing && $this->attempts() > 1) {
            return true;
        }

        Log::info('ExecuteActionRequest: skipping — not approved', [
            'action_request_id' => $this->actionRequest->id,
            'status' => $this->actionRequest->status->value,
        ]);

        return false;
    }

    private function resolveExecutor(string $type): ?ActionExecutor
    {
        $class = match ($type) {
            'delete_series', 'add_series', 'monitor_series', 'set_series_quality_profile' => SonarrActions::class,
            'delete_movie', 'add_movie', 'monitor_movie', 'set_movie_quality_profile' => RadarrActions::class,
            'cleanup_seerr_request', 'approve_seerr_request', 'decline_seerr_request' => SeerrActions::class,
            'emby_library_scan' => EmbyActions::class,
            default => null,
        };

        if ($class === null) {
            return null;
        }

        if (! class_exists($class) && ! app()->bound($class)) {
            return null;
        }

        return resolve($class);
    }
}
