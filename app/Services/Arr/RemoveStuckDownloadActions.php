<?php

declare(strict_types=1);

namespace App\Services\Arr;

use App\Cache\Services\RadarrCache;
use App\Cache\Services\SonarrCache;
use App\Enums\ServiceType;
use App\Models\ActionRequest;
use App\Models\ServiceConnection;
use App\Services\Actions\ActionExecutor;
use App\Services\Radarr\RadarrClient;
use App\Services\Sonarr\SonarrClient;
use InvalidArgumentException;

/**
 * Executes a remove_stuck_download ActionRequest: drops the stuck download's
 * queue record(s) from Sonarr/Radarr WITHOUT blocklisting (so the release
 * isn't banned) and without triggering a re-search. Used when the agent
 * decides a stuck import shouldn't be imported (e.g. "not an upgrade").
 *
 * Resolves the queue record id(s) from the downloadId server-side — the arr
 * queue-removal API is keyed by queue id, not downloadId, and a single
 * download can map to several queue rows (one per episode in a pack).
 */
class RemoveStuckDownloadActions implements ActionExecutor
{
    /**
     * @return array<string, mixed>
     */
    public function execute(ActionRequest $actionRequest): array
    {
        throw_if(
            $actionRequest->type !== 'remove_stuck_download',
            InvalidArgumentException::class,
            sprintf('RemoveStuckDownloadActions cannot execute type "%s"', $actionRequest->type),
        );

        $payload = $actionRequest->payload;
        $service = (string) ($payload['service'] ?? $actionRequest->target_service);
        $downloadId = (string) ($payload['download_id'] ?? '');

        throw_if($downloadId === '', InvalidArgumentException::class, 'download_id is required');

        $type = match ($service) {
            'sonarr' => ServiceType::Sonarr,
            'radarr' => ServiceType::Radarr,
            default => throw new InvalidArgumentException(sprintf('Unsupported service "%s"', $service)),
        };

        $serviceConnection = ServiceConnection::resolveActive($type);
        $client = $type === ServiceType::Sonarr
            ? new SonarrClient($serviceConnection)
            : new RadarrClient($serviceConnection);

        $queueParams = $type === ServiceType::Sonarr
            ? ['page' => 1, 'pageSize' => 200, 'includeUnknownSeriesItems' => 'true']
            : ['page' => 1, 'pageSize' => 200, 'includeUnknownMovieItems' => 'true'];

        $payloadQueue = $client->getQueue($queueParams);
        $records = is_array($payloadQueue['records'] ?? null) ? $payloadQueue['records'] : [];

        $ids = [];
        foreach ($records as $record) {
            if (($record['downloadId'] ?? null) === $downloadId && isset($record['id'])) {
                $ids[] = (int) $record['id'];
            }
        }

        throw_if($ids === [], InvalidArgumentException::class, sprintf('No queue items matched download id "%s"', $downloadId));

        foreach ($ids as $id) {
            // removeFromClient: evict the data; blocklist: false (per design —
            // don't ban the release); skipRedownload: true (no immediate re-search).
            $client->removeQueueItem($id, removeFromClient: true, blocklist: false, skipRedownload: true);
        }

        $type === ServiceType::Sonarr
            ? new SonarrCache($serviceConnection)->bustAll()
            : new RadarrCache($serviceConnection)->bustAll();

        return [
            'service' => $service,
            'download_id' => $downloadId,
            'removed' => count($ids),
        ];
    }
}
