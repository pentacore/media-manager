<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ServiceType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ServiceConnectionStoreRequest;
use App\Http\Requests\Admin\ServiceConnectionUpdateRequest;
use App\Models\ServiceConnection;
use Illuminate\Http\RedirectResponse;
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
                ->map(fn (ServiceConnection $connection) => [
                    'id' => $connection->id,
                    'type' => $connection->type,
                    'name' => $connection->name,
                    'url' => $connection->url,
                    'is_active' => $connection->is_active,
                    'last_seen_at' => $connection->last_seen_at?->diffForHumans(),
                    'version' => $connection->version,
                ]),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Admin/Connections/Create', [
            'serviceTypes' => collect(ServiceType::cases())->map(fn (ServiceType $type) => [
                'value' => $type->value,
                'label' => $type->label(),
            ]),
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
            'serviceTypes' => collect(ServiceType::cases())->map(fn (ServiceType $type) => [
                'value' => $type->value,
                'label' => $type->label(),
            ]),
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
        Inertia::flash('toast', ['type' => 'success', 'message' => __("Connection {$status}.")]);

        return to_route('admin.connections.index');
    }
}
