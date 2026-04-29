<?php

declare(strict_types=1);

namespace App\Http\Controllers\Emby;

use App\Enums\ServiceType;
use App\Http\Controllers\Controller;
use App\Models\ServiceConnection;
use App\Services\Emby\EmbyClient;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class NowPlayingController extends Controller
{
    public function __invoke(): Response|RedirectResponse
    {
        try {
            $connection = ServiceConnection::resolveActive(ServiceType::Emby);
        } catch (ModelNotFoundException) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('No active Emby connection configured.')]);

            return to_route('dashboard');
        }

        return Inertia::render('Emby/NowPlaying', [
            'connection' => [
                'url' => rtrim($connection->url, '/'),
            ],
            'sessions' => Inertia::defer(function () use ($connection): array {
                try {
                    $sessions = new EmbyClient($connection)->getActiveSessions();
                } catch (RequestException|ConnectionException) {
                    return [];
                }

                return array_values(array_map(fn (array $s): array => [
                    'id' => $s['Id'] ?? null,
                    'user_name' => $s['UserName'] ?? null,
                    'client' => $s['Client'] ?? null,
                    'device_name' => $s['DeviceName'] ?? null,
                    'now_playing' => isset($s['NowPlayingItem']) ? [
                        'id' => $s['NowPlayingItem']['Id'] ?? null,
                        'name' => $s['NowPlayingItem']['Name'] ?? null,
                        'type' => $s['NowPlayingItem']['Type'] ?? null,
                        'series_name' => $s['NowPlayingItem']['SeriesName'] ?? null,
                        'run_time_ticks' => $s['NowPlayingItem']['RunTimeTicks'] ?? null,
                    ] : null,
                    'play_state' => isset($s['PlayState']) ? [
                        'position_ticks' => $s['PlayState']['PositionTicks'] ?? null,
                        'is_paused' => $s['PlayState']['IsPaused'] ?? false,
                    ] : null,
                ], array_filter($sessions, fn (array $s): bool => isset($s['NowPlayingItem']))));
            }),
        ]);
    }
}
