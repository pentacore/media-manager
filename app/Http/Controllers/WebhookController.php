<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ServiceType;
use App\Events\WebhookReceived;
use App\Jobs\ProcessWebhookEvent;
use App\Models\ServiceConnection;
use App\Models\WebhookEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class WebhookController extends Controller
{
    /**
     * Dedupe identical payloads from the same connection arriving inside this
     * window. Long enough to absorb Sonarr/Radarr/Emby retry storms after a
     * 5xx; short enough that a legitimate re-occurrence later (a re-grab of
     * the same release, a replayed playback) still gets recorded.
     */
    private const int DEDUPE_WINDOW_MINUTES = 5;

    public function handle(Request $request): JsonResponse
    {
        /** @var ServiceConnection $connection */
        $connection = $request->attributes->get('service_connection');
        $payload = $request->all();
        $eventType = $this->extractEventType($request, $connection->type);
        $payloadHash = WebhookEvent::payloadHash($payload);

        // The duplicate check and insert run under a payload-keyed advisory
        // lock: two identical deliveries racing the check would otherwise
        // both pass exists() and each spawn a processing job (the dedupe
        // index is deliberately non-unique to allow re-occurrences outside
        // the window). The lock releases at commit; the loser then sees the
        // winner's row.
        $webhookEvent = DB::transaction(function () use ($connection, $eventType, $payload, $payloadHash): ?WebhookEvent {
            DB::select(
                'SELECT pg_advisory_xact_lock(hashtext(?))',
                [$connection->id.':'.$eventType.':'.$payloadHash],
            );

            $duplicate = WebhookEvent::query()
                ->where('service_connection_id', $connection->id)
                ->where('event_type', $eventType)
                ->where('payload_hash', $payloadHash)
                ->where('created_at', '>', now()->subMinutes(self::DEDUPE_WINDOW_MINUTES))
                ->exists();

            if ($duplicate) {
                return null;
            }

            return WebhookEvent::create([
                'service_connection_id' => $connection->id,
                'event_type' => $eventType,
                'payload' => $payload,
                'payload_hash' => $payloadHash,
            ]);
        });

        if (! $webhookEvent instanceof WebhookEvent) {
            return response()->json(['status' => 'received']);
        }

        $webhookEvent->setRelation('serviceConnection', $connection);

        event(new WebhookReceived($webhookEvent));
        dispatch(new ProcessWebhookEvent($webhookEvent));

        return response()->json(['status' => 'received']);
    }

    private function extractEventType(Request $request, ServiceType $serviceType): string
    {
        $key = match ($serviceType) {
            ServiceType::Emby => 'Event',
            ServiceType::Seerr => 'notification_type',
            ServiceType::Sonarr,
            ServiceType::Radarr,
            ServiceType::Prowlarr,
            ServiceType::Whisparr,
            ServiceType::SABnzbd => 'eventType',
        };

        return (string) $request->input($key, 'unknown');
    }
}
