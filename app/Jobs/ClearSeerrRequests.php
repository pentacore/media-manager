<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Cache\Services\SeerrCache;
use App\Enums\QueueLane;
use App\Enums\ServiceType;
use App\Models\ActivityLog;
use App\Models\ServiceConnection;
use App\Services\Seerr\SeerrClearInFlight;
use App\Services\Seerr\SeerrClient;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Queue\Attributes\Queue;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Queue\Attributes\UniqueFor;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Bulk-deletes Seerr requests picked on the Requests page (up to 500), one
 * DELETE each, off the HTTP request. Stops calling Seerr once it looks down
 * so a dead server cannot pin the worker for the whole list, then records
 * the outcome in the activity feed. Runs on the maintenance lane (drained by
 * the queue-ai worker): up to 500 sequential HTTP calls would otherwise pin
 * the general worker behind webhooks and approved actions. The Requests
 * page claims SeerrClearInFlight before queueing this job and hands it the
 * slot's owner token; handle() and failed() release the slot with it, so a
 * retried old job cannot free a newer clear's slot.
 */
#[Queue(QueueLane::Maintenance)]
#[UniqueFor(600)]
#[Timeout(280)]
#[Tries(1)]
final class ClearSeerrRequests implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public const int MAX_CONSECUTIVE_CONNECTION_FAILURES = 5;

    /**
     * The SeerrClearInFlight owner token the page claimed. Declared outside
     * the constructor so a job queued before the token existed unserializes
     * with null (a promoted property gets no default) and skips the release.
     */
    public ?string $slotToken = null;

    /**
     * @param  list<int>  $requestIds
     */
    public function __construct(
        public int $serviceConnectionId,
        public string $status,
        public array $requestIds,
        public ?int $userId,
        string $slotToken,
    ) {
        $this->slotToken = $slotToken;
    }

    public function uniqueId(): string
    {
        return sprintf('%d:%s', $this->serviceConnectionId, $this->status);
    }

    public function handle(): void
    {
        try {
            $this->deleteRequests();
        } finally {
            $this->releaseSlot();
        }
    }

    /**
     * The worker gave up (a timeout) without finishing handle(): free the
     * slot so the Requests page can start another clear.
     */
    public function failed(?Throwable $throwable): void
    {
        $this->releaseSlot();
    }

    private function releaseSlot(): void
    {
        if ($this->slotToken === null) {
            return;
        }

        SeerrClearInFlight::release($this->serviceConnectionId, $this->status, $this->slotToken);
    }

    private function deleteRequests(): void
    {
        $connection = ServiceConnection::query()->find($this->serviceConnectionId);

        if (! $connection instanceof ServiceConnection || ! $connection->is_active || $connection->type !== ServiceType::Seerr) {
            Log::info('ClearSeerrRequests: connection missing, inactive or not Seerr; nothing cleared', [
                'service_connection_id' => $this->serviceConnectionId,
            ]);

            return;
        }

        $seerrClient = new SeerrClient($connection);
        $deleted = 0;
        $failed = 0;
        $consecutiveConnectionFailures = 0;

        foreach ($this->requestIds as $requestId) {
            if ($consecutiveConnectionFailures >= self::MAX_CONSECUTIVE_CONNECTION_FAILURES) {
                $failed++;

                continue;
            }

            try {
                $seerrClient->deleteRequest($requestId);
                $deleted++;
                $consecutiveConnectionFailures = 0;
            } catch (ConnectionException) {
                $failed++;
                $consecutiveConnectionFailures++;
            } catch (RequestException) {
                $failed++;
                $consecutiveConnectionFailures = 0;
            }
        }

        new SeerrCache($connection)->bustAll();

        ActivityLog::create([
            'user_id' => $this->userId,
            'service_connection_id' => $connection->id,
            'action' => 'seerr.requests_cleared',
            'description' => sprintf('Cleared %d of %d %s Seerr request(s).', $deleted, count($this->requestIds), $this->status),
            'metadata' => [
                'status' => $this->status,
                'deleted' => $deleted,
                'failed' => $failed,
            ],
        ]);
    }
}
