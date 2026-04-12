<?php

declare(strict_types=1);

namespace App\Http\Controllers;

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

        WebhookEvent::create([
            'service_connection_id' => $connection->id,
            'event_type' => $request->input('eventType', 'unknown'),
            'payload' => $request->all(),
        ]);

        return response()->json(['status' => 'received']);
    }
}
