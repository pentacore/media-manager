<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\ServiceConnection;
use App\Models\WebhookEvent;
use App\Services\MediaReplacement\ImportedSubtitleAuditor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Runs the automatic subtitle check for a completed import.
 *
 * Queued rather than inline in the webhook handler: the candidate search
 * sweeps every configured indexer and its HTTP call alone allows 120 seconds.
 * The delay also gives the arr's mediainfo scan time to finish, so the
 * imported file's subtitle list is populated before it is read.
 *
 * Everything the audit needs is carried on the job — the connection id and the
 * payload — because the event row need not survive the delay. With webhook
 * capture off, ProcessWebhookEvent deletes it the moment the handler returns,
 * far inside these 30 seconds; re-reading the event would then find nothing and
 * this feature would be inert for every operator who has capture turned off.
 * The event id is carried too, but only to link the resulting request back to
 * the import while that row still exists; audit() accepts null.
 */
class AuditImportedSubtitles implements ShouldQueue
{
    use Queueable;

    public const int DELAY_SECONDS = 30;

    /**
     * The connection lookup happens before any useful work, so a transient
     * database failure there would otherwise drop the audit for an import that
     * is never reported again. Mirrors SweepCompetingGrabs, which retries for
     * the same reason. A retry cannot double-request: the only work after the
     * lookup is audit(), whose per-target attempt cap bounds a repeat.
     */
    public int $tries = 2;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public int $webhookEventId,
        public int $serviceConnectionId,
        public array $payload,
    ) {}

    public static function queueFor(WebhookEvent $webhookEvent): void
    {
        self::dispatch(
            $webhookEvent->id,
            $webhookEvent->service_connection_id,
            is_array($webhookEvent->payload) ? $webhookEvent->payload : [],
        )->delay(now()->addSeconds(self::DELAY_SECONDS));
    }

    public function handle(ImportedSubtitleAuditor $importedSubtitleAuditor): void
    {
        $serviceConnection = ServiceConnection::find($this->serviceConnectionId);

        if (! $serviceConnection instanceof ServiceConnection) {
            // The one path that drops the audit, and the auditor never runs to
            // record why, so this is a warning rather than a debug line: a
            // dropped check must leave a trace.
            Log::warning('Automatic subtitle check dropped: the service connection no longer exists.', [
                'service_connection_id' => $this->serviceConnectionId,
                'webhook_event_id' => $this->webhookEventId,
            ]);

            return;
        }

        // Null once the event row has been trimmed. That only costs the
        // resulting request its webhook_event_id link, a nullable column the
        // database itself nulls when an event is deleted.
        $webhookEvent = WebhookEvent::find($this->webhookEventId);

        $importedSubtitleAuditor->audit($serviceConnection, $this->payload, $webhookEvent);
    }
}
