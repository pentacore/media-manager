<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\ServiceConnection;
use App\Models\WebhookEvent;
use App\Services\MediaReplacement\ImportedSubtitleAuditor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Runs the automatic subtitle check for a completed import.
 *
 * Queued rather than inline in the webhook handler: the candidate search
 * sweeps every configured indexer and its HTTP call alone allows 120 seconds.
 * The delay also gives the arr's mediainfo scan time to finish, so the
 * imported file's subtitle list is populated before it is read.
 *
 * Carries the event id rather than the model so a connection deleted during
 * the wait — which cascades the event row away — lands on the pruned guard
 * instead of a stale in-payload copy.
 */
class AuditImportedSubtitles implements ShouldQueue
{
    use Queueable;

    public const int DELAY_SECONDS = 30;

    public function __construct(
        public int $webhookEventId,
    ) {}

    public static function queueFor(WebhookEvent $webhookEvent): void
    {
        self::dispatch($webhookEvent->id)->delay(now()->addSeconds(self::DELAY_SECONDS));
    }

    public function handle(ImportedSubtitleAuditor $importedSubtitleAuditor): void
    {
        $webhookEvent = WebhookEvent::find($this->webhookEventId);

        if (! $webhookEvent instanceof WebhookEvent) {
            return;
        }

        $serviceConnection = $webhookEvent->serviceConnection;

        // Defensive: the column is NOT NULL and cascades, so a surviving event
        // always has its connection. audit() cannot take null, and the
        // alternative to checking is a TypeError in the queue.
        if (! $serviceConnection instanceof ServiceConnection) {
            return;
        }

        $importedSubtitleAuditor->audit(
            $serviceConnection,
            is_array($webhookEvent->payload) ? $webhookEvent->payload : [],
            $webhookEvent,
        );
    }
}
