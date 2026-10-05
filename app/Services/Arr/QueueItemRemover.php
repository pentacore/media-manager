<?php

declare(strict_types=1);

namespace App\Services\Arr;

use App\Enums\ServiceType;
use App\Models\ServiceConnection;
use App\Services\Audit\AuditLogger;
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
    public function __construct(
        private AuditLogger $auditLogger,
        private ArrConnections $arrConnections,
    ) {}

    /**
     * @throws RequestException|ConnectionException
     */
    public function remove(ServiceConnection $serviceConnection, int $queueId, bool $blocklist): void
    {
        throw_unless(
            in_array($serviceConnection->type, [ServiceType::Sonarr, ServiceType::Radarr], true),
            InvalidArgumentException::class,
            sprintf('%s has no download queue.', $serviceConnection->type->label()),
        );

        $arrClient = $this->arrConnections->client($serviceConnection);

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
