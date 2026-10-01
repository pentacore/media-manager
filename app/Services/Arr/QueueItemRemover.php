<?php

declare(strict_types=1);

namespace App\Services\Arr;

use App\Enums\ServiceType;
use App\Models\ServiceConnection;
use App\Services\Audit\AuditLogger;
use App\Services\Radarr\RadarrClient;
use App\Services\Sonarr\SonarrClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use InvalidArgumentException;

/**
 * Removes one Sonarr/Radarr download-queue item — optionally blocklisting the
 * release so a fresh search runs — and audits it. The row menu and the bulk
 * endpoint both call this.
 */
final readonly class QueueItemRemover
{
    public function __construct(private AuditLogger $auditLogger) {}

    /**
     * @throws RequestException|ConnectionException
     */
    public function remove(ServiceConnection $serviceConnection, int $queueId, bool $blocklist): void
    {
        $arrClient = match ($serviceConnection->type) {
            ServiceType::Sonarr => new SonarrClient($serviceConnection),
            ServiceType::Radarr => new RadarrClient($serviceConnection),
            default => throw new InvalidArgumentException(sprintf('%s has no download queue.', $serviceConnection->type->label())),
        };

        $arrClient->removeQueueItem(
            id: $queueId,
            removeFromClient: true,
            blocklist: $blocklist,
            skipRedownload: ! $blocklist,
        );

        $service = $serviceConnection->type->value;

        $this->auditLogger->record(
            $blocklist ? 'queue.blocklisted' : 'queue.removed',
            $serviceConnection,
            $blocklist
                ? sprintf('Removed %s queue item %d and blocklisted the release.', ucfirst($service), $queueId)
                : sprintf('Removed %s queue item %d.', ucfirst($service), $queueId),
            context: ['service' => $service, 'queue_id' => $queueId],
        );
    }
}
