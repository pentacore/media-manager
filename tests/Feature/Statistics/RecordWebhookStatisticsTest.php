<?php

declare(strict_types=1);

use App\Enums\ServiceType;
use App\Events\WebhookEventProcessed;
use App\Models\ServiceConnection;
use App\Models\StatRollup;
use App\Models\WebhookEvent;

function makeProcessedEvent(ServiceType $serviceType, string $eventType, array $payload = []): WebhookEvent
{
    $connection = ServiceConnection::factory()->create(['type' => $serviceType]);

    return WebhookEvent::factory()->create([
        'service_connection_id' => $connection->id,
        'event_type' => $eventType,
        'payload' => $payload,
    ]);
}

it('records webhook received counts with service and event dimensions', function (): void {
    event(new WebhookEventProcessed(makeProcessedEvent(ServiceType::Sonarr, 'Download')));

    $statRollup = StatRollup::query()->where(['metric' => 'webhooks.received', 'period' => 'day'])->sole();

    expect($statRollup->count)->toBe(1)
        ->and($statRollup->dimensions)->toEqualCanonicalizing(['event_type' => 'Download', 'service' => 'sonarr']);
});

it('records download lifecycle metrics from arr and sabnzbd events', function (): void {
    event(new WebhookEventProcessed(makeProcessedEvent(ServiceType::Sonarr, 'Grab')));
    event(new WebhookEventProcessed(makeProcessedEvent(ServiceType::SABnzbd, 'complete')));
    event(new WebhookEventProcessed(makeProcessedEvent(ServiceType::SABnzbd, 'failed')));

    expect(StatRollup::query()->where('metric', 'downloads.grabbed')->where('period', 'day')->sole()->count)->toBe(1)
        ->and(StatRollup::query()->where('metric', 'downloads.fetched')->where('period', 'day')->sole()->count)->toBe(1)
        ->and(StatRollup::query()->where('metric', 'downloads.failed')->where('period', 'day')->sole()->count)->toBe(1);
});

it('does not double-count a download completed by sabnzbd and imported by an arr', function (): void {
    event(new WebhookEventProcessed(makeProcessedEvent(ServiceType::SABnzbd, 'complete')));
    event(new WebhookEventProcessed(makeProcessedEvent(ServiceType::Sonarr, 'Download')));

    expect(StatRollup::query()->where('metric', 'downloads.completed')->where('period', 'day')->sole()->count)->toBe(1);
});

it('records the seerr request funnel', function (): void {
    event(new WebhookEventProcessed(makeProcessedEvent(ServiceType::Seerr, 'MEDIA_PENDING', ['notification_type' => 'MEDIA_PENDING'])));
    event(new WebhookEventProcessed(makeProcessedEvent(ServiceType::Seerr, 'MEDIA_AUTO_APPROVED', ['notification_type' => 'MEDIA_AUTO_APPROVED'])));

    expect(StatRollup::query()->where('metric', 'requests.created')->where('period', 'day')->sole()->count)->toBe(1)
        ->and(StatRollup::query()->where('metric', 'requests.approved')->where('period', 'day')->sole()->count)->toBe(1);
});
