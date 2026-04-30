<?php

declare(strict_types=1);

namespace App\Services\Sabnzbd;

use App\Enums\ServiceType;
use App\Events\SabnzbdDownloadCountsChanged;
use App\Models\ServiceConnection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;

/**
 * Counts SAB queue + history rows for the sidebar Downloads badge.
 * SAB removes finished-and-imported downloads from history itself, so
 * anything still listed under "completed" is something the user has to
 * look at — usually a post-processing failure or a missing nzb script.
 */
class SabnzbdDownloadCounter
{
    public const string CACHE_KEY = 'sabnzbd:download-counts';

    public const int CACHE_TTL = 600;

    /**
     * @return array{queued: int, completed: int}
     */
    public function get(): array
    {
        $value = Cache::get(self::CACHE_KEY);

        if (is_array($value) && isset($value['queued'], $value['completed'])) {
            return ['queued' => (int) $value['queued'], 'completed' => (int) $value['completed']];
        }

        return ['queued' => 0, 'completed' => 0];
    }

    /**
     * Re-fetch from SAB, store, broadcast. Returns the fresh counts so
     * webhook handlers can react inline if needed.
     *
     * @return array{queued: int, completed: int}
     */
    public function recompute(): array
    {
        $counts = ['queued' => 0, 'completed' => 0];

        try {
            $connection = ServiceConnection::resolveActive(ServiceType::SABnzbd);
        } catch (ModelNotFoundException) {
            Cache::put(self::CACHE_KEY, $counts, self::CACHE_TTL);
            event(new SabnzbdDownloadCountsChanged($counts['queued'], $counts['completed']));

            return $counts;
        }

        $sabnzbdClient = new SabnzbdClient($connection);

        try {
            $queue = $sabnzbdClient->getQueue();
            $counts['queued'] = is_array($queue['slots'] ?? null) ? count($queue['slots']) : 0;
        } catch (RequestException|ConnectionException) {
            // leave queued at 0 — partial data is better than 500ing the badge.
        }

        try {
            $history = $sabnzbdClient->getHistory();
            $slots = is_array($history['slots'] ?? null) ? $history['slots'] : [];
            // SAB exposes per-row 'status'; rows still in SAB after finishing
            // mean post-processing didn't auto-prune them. Surface every
            // remaining row regardless of status so the user notices.
            $counts['completed'] = count($slots);
        } catch (RequestException|ConnectionException) {
            // same fallback.
        }

        Cache::put(self::CACHE_KEY, $counts, self::CACHE_TTL);
        event(new SabnzbdDownloadCountsChanged($counts['queued'], $counts['completed']));

        return $counts;
    }
}
