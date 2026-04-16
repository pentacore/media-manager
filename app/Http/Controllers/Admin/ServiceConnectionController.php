<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\ServiceType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ServiceConnectionStoreRequest;
use App\Http\Requests\Admin\ServiceConnectionUpdateRequest;
use App\Jobs\FetchLatestServiceVersion;
use App\Jobs\PingServiceHealth;
use App\Models\ServiceConnection;
use App\Services\Emby\EmbyClient;
use App\Services\Radarr\RadarrClient;
use App\Services\Seerr\SeerrClient;
use App\Services\Sonarr\SonarrClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ServiceConnectionController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Admin/Connections/Index', [
            'connections' => ServiceConnection::query()
                ->orderBy('name')
                ->get()
                ->map(fn (ServiceConnection $connection): array => [
                    'id' => $connection->id,
                    'type' => $connection->type,
                    'name' => $connection->name,
                    'url' => $connection->url,
                    'is_active' => $connection->is_active,
                    'health_status' => $connection->health_status?->value,
                    'last_seen_at' => $connection->last_seen_at?->diffForHumans(),
                    'version' => $connection->version,
                    'latest_version' => $connection->latest_version,
                    'update_available' => $connection->latest_version !== null
                        && $connection->version !== null
                        && $connection->latest_version !== $connection->version,
                ]),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Admin/Connections/Create', [
            'serviceTypes' => ServiceType::mapForSelect(labelKey: 'label'),
        ]);
    }

    public function store(ServiceConnectionStoreRequest $request): RedirectResponse
    {
        ServiceConnection::create($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Connection created.')]);

        return to_route('admin.connections.index');
    }

    public function edit(ServiceConnection $connection): Response
    {
        return Inertia::render('Admin/Connections/Edit', [
            'connection' => [
                'id' => $connection->id,
                'type' => $connection->type,
                'name' => $connection->name,
                'url' => $connection->url,
                'api_key' => $connection->api_key,
                'webhook_token' => $connection->webhook_token,
                'is_active' => $connection->is_active,
            ],
            'serviceTypes' => ServiceType::mapForSelect(labelKey: 'label'),
        ]);
    }

    public function update(ServiceConnectionUpdateRequest $request, ServiceConnection $connection): RedirectResponse
    {
        $connection->update($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Connection updated.')]);

        return to_route('admin.connections.index');
    }

    public function destroy(ServiceConnection $connection): RedirectResponse
    {
        $connection->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Connection deleted.')]);

        return to_route('admin.connections.index');
    }

    public function toggle(ServiceConnection $connection): RedirectResponse
    {
        $connection->update(['is_active' => ! $connection->is_active]);

        $status = $connection->is_active ? 'enabled' : 'disabled';
        Inertia::flash('toast', ['type' => 'success', 'message' => __(sprintf('Connection %s.', $status))]);

        return to_route('admin.connections.index');
    }

    public function checkHealth(ServiceConnection $connection): RedirectResponse
    {
        PingServiceHealth::dispatch($connection);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Health check queued for :name.', ['name' => $connection->name]),
        ]);

        return back();
    }

    public function checkVersion(ServiceConnection $connection): RedirectResponse
    {
        FetchLatestServiceVersion::dispatch($connection);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Version check queued for :name.', ['name' => $connection->name]),
        ]);

        return back();
    }

    public function test(Request $request): JsonResponse
    {
        $request->validate([
            'type' => ['required', 'string', ServiceType::validationRule()],
            'url' => ['required', 'url', 'max:500'],
            'api_key' => ['required', 'string', 'max:500'],
        ]);

        $connection = new ServiceConnection([
            'type' => $request->input('type'),
            'url' => $request->input('url'),
            'api_key' => $request->input('api_key'),
        ]);

        try {
            $client = match ($connection->type) {
                ServiceType::Sonarr => new SonarrClient($connection),
                ServiceType::Radarr => new RadarrClient($connection),
                ServiceType::Emby => new EmbyClient($connection),
                ServiceType::Seerr => new SeerrClient($connection),
            };

            $result = match ($connection->type) {
                ServiceType::Emby => $client->getSystemInfo(),
                ServiceType::Seerr => $client->getStatus(),
                default => $client->getSystemStatus(),
            };

            $version = $result['version'] ?? $result['Version'] ?? null;

            return response()->json([
                'success' => true,
                'message' => 'Connection successful.',
                'version' => $version,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Connection failed: '.$e->getMessage(),
            ], 422);
        }
    }
}
