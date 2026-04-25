<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Events\WebhookReceived;
use App\Jobs\ProcessWebhookEvent;
use App\Models\ServiceConnection;
use App\Models\WebhookEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WebhookController extends Controller
{
    public function handle(Request $request): JsonResponse
    {
        /** @var ServiceConnection $connection */
        $connection = $request->attributes->get('service_connection');
        $payload = $request->all();
        $eventType = (string) $request->input('eventType', 'unknown');

        $webhookEvent = WebhookEvent::firstOrCreate([
            'service_connection_id' => $connection->id,
            'event_type' => $eventType,
            'payload_hash' => WebhookEvent::payloadHash($payload),
        ], [
            'payload' => $payload,
        ]);

        $webhookEvent->setRelation('serviceConnection', $connection);

        if ($webhookEvent->wasRecentlyCreated) {
            event(new WebhookReceived($webhookEvent));
            dispatch(new ProcessWebhookEvent($webhookEvent));
        }

        return response()->json(['status' => 'received']);
    }
}
