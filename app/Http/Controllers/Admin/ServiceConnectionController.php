<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\ServiceType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ServiceConnectionStoreRequest;
use App\Http\Requests\Admin\ServiceConnectionUpdateRequest;
use App\Http\Resources\ServiceConnectionResource;
use App\Jobs\FetchLatestServiceVersion;
use App\Jobs\PingServiceHealth;
use App\Models\ServiceConnection;
use App\Services\Prowlarr\ProwlarrClient;
use App\Services\ServiceClientFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class ServiceConnectionController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('Admin/Connections/Index', [
            'connections' => ServiceConnectionResource::collection(
                ServiceConnection::query()->orderBy('name')->get()
            )->toArray($request),
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
                'api_key_set' => $serviceConnection->api_key !== '' && $serviceConnection->api_key !== null,
                'webhook_token_set' => $serviceConnection->webhook_token !== '' && $serviceConnection->webhook_token !== null,
                'is_active' => $serviceConnection->is_active,
            ],
            'serviceTypes' => ServiceType::mapForSelect(labelKey: 'label'),
            'indexers' => $serviceConnection->type === ServiceType::Prowlarr
                ? Inertia::defer(fn (): array => $this->loadProwlarrIndexers($serviceConnection))
                : [],
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadProwlarrIndexers(ServiceConnection $serviceConnection): array
    {
        try {
            return (new ProwlarrClient($serviceConnection))->listIndexers();
        } catch (Throwable $e) {
            Log::warning('Failed to load Prowlarr indexers for edit page', [
                'connection_id' => $serviceConnection->id,
                'exception' => $e::class,
            ]);

            return [];
        }
    }

    public function update(ServiceConnectionUpdateRequest $serviceConnectionUpdateRequest, ServiceConnection $serviceConnection): RedirectResponse
    {
        $validated = $serviceConnectionUpdateRequest->validated();

        // Do not wipe existing secrets when the admin submits the form without
        // retyping them (blank/null means "keep existing value").
        foreach (['api_key', 'webhook_token'] as $secretField) {
            $value = $validated[$secretField] ?? null;

            if (! is_string($value) || trim($value) === '') {
                unset($validated[$secretField]);
            }
        }

        $serviceConnection->update($validated);

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
            $client = resolve(ServiceClientFactory::class)->make($serviceConnection);

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
