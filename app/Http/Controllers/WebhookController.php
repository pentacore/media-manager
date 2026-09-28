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
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
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
        // Sonarr/Radarr/Prowlarr can only send the secret as ?token=, and
        // Request::all() merges the query string in. Never persist it: the
        // payload is shown on the Webhook Log and handed to the decision agent.
        $payload = $request->query->has('token') ? Arr::except($request->all(), ['token']) : $request->all();
        $eventType = $this->extractEventType($request, $connection->type);
        $payloadHash = WebhookEvent::payloadHash($payload);
        $dedupeKey = sprintf('webhook-dedupe:%d:%s:%s', $connection->id, $eventType, $payloadHash);

        // The duplicate check and insert run under a payload-keyed advisory
        // lock: two identical deliveries racing the check would otherwise
        // both pass exists() and each spawn a processing job (the dedupe
        // index is deliberately non-unique to allow re-occurrences outside
        // the window). The lock releases at commit; the loser then sees the
        // winner's row.
        $webhookEvent = DB::transaction(function () use ($connection, $eventType, $payload, $payloadHash, $dedupeKey): ?WebhookEvent {
            DB::select(
                'SELECT pg_advisory_xact_lock(hashtext(?))',
                [$connection->id.':'.$eventType.':'.$payloadHash],
            );

            // The cache key outlives the row: with capture off,
            // ProcessWebhookEvent deletes the row right after handling, so a
            // redelivery inside the window would otherwise find no row and
            // no dedupe index entry to match against.
            $duplicate = Cache::has($dedupeKey) || WebhookEvent::query()
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

        // Written after commit and before the processing job runs, so the
        // row cannot be trimmed by capture-off processing before either the
        // row or this key is visible to a racing redelivery.
        Cache::put($dedupeKey, true, now()->addMinutes(self::DEDUPE_WINDOW_MINUTES));

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
