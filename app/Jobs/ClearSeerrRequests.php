<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Cache\Services\SeerrCache;
use App\Enums\ServiceType;
use App\Models\ActivityLog;
use App\Models\ServiceConnection;
use App\Services\Seerr\SeerrClient;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Queue\Attributes\UniqueFor;
use Illuminate\Support\Facades\Log;

/**
 * Bulk-deletes Seerr requests picked on the Requests page (up to 500), one
 * DELETE each, off the HTTP request. Stops calling Seerr once it looks down
 * so a dead server cannot pin the worker for the whole list, then records
 * the outcome in the activity feed.
 */
#[UniqueFor(600)]
#[Timeout(280)]
#[Tries(1)]
final class ClearSeerrRequests implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public const int MAX_CONSECUTIVE_CONNECTION_FAILURES = 5;

    /**
     * @param  list<int>  $requestIds
     */
    public function __construct(
        public int $serviceConnectionId,
        public string $status,
        public array $requestIds,
        public ?int $userId,
    ) {}

    public function uniqueId(): string
    {
        return sprintf('%d:%s', $this->serviceConnectionId, $this->status);
    }

    public function handle(): void
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
