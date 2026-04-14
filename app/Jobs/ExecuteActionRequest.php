<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\ActionRequestStatus;
use App\Events\ActionRequestStatusChanged;
use App\Models\ActionRequest;
use App\Services\Actions\ActionExecutor;
use App\Services\Emby\EmbyActions;
use App\Services\Jellyseerr\JellyseerrActions;
use App\Services\Radarr\RadarrActions;
use App\Services\Sonarr\SonarrActions;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class ExecuteActionRequest implements ShouldQueue
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
        $this->actionRequest->refresh();

        // Guard: only run when Approved (either auto or post-approval).
        if ($this->actionRequest->status !== ActionRequestStatus::Approved) {
            Log::info('ExecuteActionRequest: skipping — not approved', [
                'action_request_id' => $this->actionRequest->id,
                'status' => $this->actionRequest->status->value,
            ]);

            return;
        }

        $this->actionRequest->update(['status' => ActionRequestStatus::Executing]);
        event(new ActionRequestStatusChanged($this->actionRequest));

        $executor = $this->resolveExecutor($this->actionRequest->type);

        if (! $executor instanceof ActionExecutor) {
            $this->fail('no_executor', ['message' => sprintf('No executor registered for type "%s"', $this->actionRequest->type)]);

            return;
        }

        try {
            $result = $executor->execute($this->actionRequest);
        } catch (Throwable $throwable) {
            $this->fail('execution_failed', [
                'message' => $throwable->getMessage(),
                'exception' => $throwable::class,
            ]);

            return;
        }

        $this->actionRequest->update([
            'status' => ActionRequestStatus::Completed,
            'result' => ['success' => true, ...$result],
        ]);
        event(new ActionRequestStatusChanged($this->actionRequest));
    }

    public function failed(?Throwable $throwable): void
    {
        // Called by Laravel when all retries are exhausted OR `$this->fail()` is called.
        // If we already wrote the result via fail(), don't overwrite.
        $this->actionRequest->refresh();
        if ($this->actionRequest->status === ActionRequestStatus::Failed) {
            return;
        }

        $this->actionRequest->update([
            'status' => ActionRequestStatus::Failed,
            'result' => ['success' => false, 'message' => $throwable?->getMessage() ?? 'Job failed'],
        ]);
        event(new ActionRequestStatusChanged($this->actionRequest));
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function fail(string $reason, array $extra = []): void
    {
        $this->actionRequest->update([
            'status' => ActionRequestStatus::Failed,
            'result' => ['success' => false, 'reason' => $reason, ...$extra],
        ]);
        event(new ActionRequestStatusChanged($this->actionRequest));
    }

    private function resolveExecutor(string $type): ?ActionExecutor
    {
        $class = match ($type) {
            'delete_series' => SonarrActions::class,
            'delete_movie' => RadarrActions::class,
            'cleanup_jellyseerr_request' => JellyseerrActions::class,
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
