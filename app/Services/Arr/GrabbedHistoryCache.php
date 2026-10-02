<?php

declare(strict_types=1);

namespace App\Services\Arr;

use App\Models\ServiceConnection;
use Illuminate\Support\Facades\Cache;

/**
 * Remembers which Sonarr/Radarr history records MediaManager rendered as
 * "grabbed", so mark-as-failed can refuse any other id server-side. The arr
 * APIs have no read-one-history-record endpoint, and a record's event type
 * never changes once written, so what the History tab showed is
 * authoritative. Keyed per connection because history ids overlap between
 * instances.
 */
final readonly class GrabbedHistoryCache
{
    public const int TTL_SECONDS = 86_400;

    /**
     * @param  list<int>  $historyIds
     */
    public function remember(ServiceConnection $serviceConnection, array $historyIds): void
    {
        if ($historyIds === []) {
            return;
        }

        $entries = [];

        foreach ($historyIds as $historyId) {
            $entries[$this->key($serviceConnection->id, $historyId)] = true;
        }

        Cache::putMany($entries, self::TTL_SECONDS);
    }

    public function isGrabbed(ServiceConnection $serviceConnection, int $historyId): bool
    {
        return Cache::get($this->key($serviceConnection->id, $historyId)) === true;
    }

    private function key(int $connectionId, int $historyId): string
    {
        return sprintf('arr-history-grabbed:%d:%d', $connectionId, $historyId);
    }
}
