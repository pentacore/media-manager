<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\Presence\PresenceTracker;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Artisan;

class HeartbeatController
{
    public function __construct(private readonly PresenceTracker $presenceTracker) {}

    public function __invoke(Request $request): Response
    {
        $wasEmpty = $this->presenceTracker->markActive((string) $request->user()->id);

        // Empty → non-empty transition means nobody else has triggered the
        // scheduled warm yet for this active session. Queue an immediate
        // run so the user doesn't have to wait up to a minute for the next
        // scheduler tick before the Sonarr / Radarr / Seerr caches go warm.
        if ($wasEmpty) {
            Artisan::queue('services:warm-caches');
        }

        return response()->noContent();
    }
}
