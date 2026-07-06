<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Enums\ServiceType;
use App\Events\WebhookEventProcessed;
use App\Services\Statistics\StatsRecorder;
use Carbon\CarbonImmutable;

/**
 * Counts trim-safe streams at ingest time: webhook rows may be deleted
 * immediately after processing (capture off), SABnzbd/Seerr activity has
 * no durable table — so these metrics can only be recorded here, while
 * the event is in hand. Aggregator must NOT also compute them.
 */
class RecordWebhookStatistics
{
    public function __construct(private readonly StatsRecorder $statsRecorder) {}

    public function handle(WebhookEventProcessed $webhookEventProcessed): void
    {
        $webhookEvent = $webhookEventProcessed->webhookEvent;
        $webhookEvent->loadMissing('serviceConnection');

        $service = $webhookEvent->serviceConnection?->type;

        if (! $service instanceof ServiceType) {
            return;
        }

        $at = CarbonImmutable::now('UTC');
        $eventType = (string) $webhookEvent->event_type;

        $this->statsRecorder->increment('webhooks.received', [
            'service' => $service->value,
            'event_type' => $eventType,
        ], $at);

        $this->recordDownloads($service, $eventType, $at);
        $this->recordRequests($service, $eventType, $at);
    }

    private function recordDownloads(ServiceType $service, string $eventType, CarbonImmutable $at): void
    {
        $metric = match (true) {
            in_array($service, [ServiceType::Sonarr, ServiceType::Radarr, ServiceType::Whisparr], true) => match ($eventType) {
                'Grab' => 'downloads.grabbed',
                'Download' => 'downloads.completed',
                default => null,
            },
            $service === ServiceType::SABnzbd => match ($eventType) {
                'complete' => 'downloads.completed',
                'failed' => 'downloads.failed',
                default => null,
            },
            default => null,
        };

        if ($metric !== null) {
            $this->statsRecorder->increment($metric, ['service' => $service->value], $at);
        }
    }

    private function recordRequests(ServiceType $service, string $eventType, CarbonImmutable $at): void
    {
        if ($service !== ServiceType::Seerr) {
            return;
        }

        $metric = match ($eventType) {
            'MEDIA_PENDING' => 'requests.created',
            'MEDIA_APPROVED', 'MEDIA_AUTO_APPROVED' => 'requests.approved',
            'MEDIA_DECLINED' => 'requests.declined',
            'MEDIA_AVAILABLE' => 'requests.available',
            'MEDIA_FAILED' => 'requests.failed',
            default => null,
        };

        if ($metric !== null) {
            $this->statsRecorder->increment($metric, [], $at);
        }
    }
}
