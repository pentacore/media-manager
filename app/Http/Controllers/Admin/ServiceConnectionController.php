<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use Throwable;
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
                ->map(fn (ServiceConnection $serviceConnection): array => [
                    'id' => $serviceConnection->id,
                    'type' => $serviceConnection->type,
                    'name' => $serviceConnection->name,
                    'url' => $serviceConnection->url,
                    'is_active' => $serviceConnection->is_active,
                    'health_status' => $serviceConnection->health_status?->value,
                    'last_seen_at' => $serviceConnection->last_seen_at?->diffForHumans(),
                    'version' => $serviceConnection->version,
                    'latest_version' => $serviceConnection->latest_version,
                    'update_available' => $serviceConnection->latest_version !== null
                        && $serviceConnection->version !== null
                        && $serviceConnection->latest_version !== $serviceConnection->version,
                ]),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Admin/Connections/Create', [
            'serviceTypes' => ServiceType::mapForSelect(labelKey: 'label'),
        ]);
    }

    public function store(ServiceConnectionStoreRequest $serviceConnectionStoreRequest): RedirectResponse
    {
        ServiceConnection::create($serviceConnectionStoreRequest->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Connection created.')]);

        return to_route('admin.connections.index');
    }

    public function edit(ServiceConnection $serviceConnection): Response
    {
        return Inertia::render('Admin/Connections/Edit', [
            'connection' => [
                'id' => $serviceConnection->id,
                'type' => $serviceConnection->type,
                'name' => $serviceConnection->name,
                'url' => $serviceConnection->url,
                'api_key' => $serviceConnection->api_key,
                'webhook_token' => $serviceConnection->webhook_token,
                'is_active' => $serviceConnection->is_active,
            ],
            'serviceTypes' => ServiceType::mapForSelect(labelKey: 'label'),
        ]);
    }

    public function update(ServiceConnectionUpdateRequest $serviceConnectionUpdateRequest, ServiceConnection $serviceConnection): RedirectResponse
    {
        $serviceConnection->update($serviceConnectionUpdateRequest->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Connection updated.')]);

        return to_route('admin.connections.index');
    }

    public function destroy(ServiceConnection $serviceConnection): RedirectResponse
    {
        $serviceConnection->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Connection deleted.')]);

        return to_route('admin.connections.index');
    }

    public function toggle(ServiceConnection $serviceConnection): RedirectResponse
    {
        $serviceConnection->update(['is_active' => ! $serviceConnection->is_active]);

        $status = $serviceConnection->is_active ? 'enabled' : 'disabled';
        Inertia::flash('toast', ['type' => 'success', 'message' => __(sprintf('Connection %s.', $status))]);

        return to_route('admin.connections.index');
    }

    public function checkHealth(ServiceConnection $serviceConnection): RedirectResponse
    {
        dispatch(new PingServiceHealth($serviceConnection));

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Health check queued for :name.', ['name' => $serviceConnection->name]),
        ]);

        return back();
    }

    public function checkVersion(ServiceConnection $serviceConnection): RedirectResponse
    {
        dispatch(new FetchLatestServiceVersion($serviceConnection));

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Version check queued for :name.', ['name' => $serviceConnection->name]),
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

        $serviceConnection = new ServiceConnection([
            'type' => $request->input('type'),
            'url' => $request->input('url'),
            'api_key' => $request->input('api_key'),
        ]);

        try {
            $client = match ($serviceConnection->type) {
                ServiceType::Sonarr => new SonarrClient($serviceConnection),
                ServiceType::Radarr => new RadarrClient($serviceConnection),
                ServiceType::Emby => new EmbyClient($serviceConnection),
                ServiceType::Seerr => new SeerrClient($serviceConnection),
            };

            $result = match ($serviceConnection->type) {
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
        } catch (Throwable $throwable) {
            return response()->json([
                'success' => false,
                'message' => 'Connection failed: '.$throwable->getMessage(),
            ], 422);
        }
    }
}
