<?php

declare(strict_types=1);

namespace App\Http\Controllers\Bazarr;

use App\Enums\ServiceType;
use App\Http\Requests\Bazarr\AdminSettingsRequest;
use App\Models\ActivityLog;
use App\Models\BazarrServiceLink;
use App\Models\ServiceConnection;
use App\Services\Bazarr\BazarrClient;
use App\Services\Bazarr\BazarrSettingsAdapter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class AdminController extends BazarrController
{
    public function index(Request $request, BazarrSettingsAdapter $bazarrSettingsAdapter): Response
    {
        $request->validate($this->commonRules());
        $connectionProps = $this->connectionProps($request);
        $connection = $this->selectedConnection($connectionProps);

        return Inertia::render('Bazarr/Admin', [
            ...$connectionProps,
            'settings' => $connection instanceof ServiceConnection ? $bazarrSettingsAdapter->read($connection) : null,
            'settings_writable' => $connection instanceof ServiceConnection
                && (new BazarrClient($connection)->getCapabilities()['settings_adapter'] ?? false),
            'mappings' => $connection instanceof ServiceConnection ? $this->mappings($connection) : [],
            'bazarr_external_url' => $connection?->linkUrl(),
            'action_rules_url' => route('actions.rules.index'),
        ]);
    }

    public function update(
        AdminSettingsRequest $adminSettingsRequest,
        BazarrSettingsAdapter $bazarrSettingsAdapter,
    ): RedirectResponse {
        $validated = $adminSettingsRequest->validated();
        $connection = ServiceConnection::query()
            ->where('type', ServiceType::Bazarr)
            ->where('is_active', true)
            ->findOrFail($validated['connection']);
        $changedKeys = $bazarrSettingsAdapter->update($connection, $validated['settings']);

        ActivityLog::create([
            'user_id' => $adminSettingsRequest->user()?->id,
            'service_connection_id' => $connection->id,
            'action' => 'bazarr.settings.updated',
            'description' => 'Updated Bazarr non-secret settings.',
            'metadata' => ['changed_keys' => $changedKeys],
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Bazarr settings updated.')]);

        return back();
    }

    /**
     * @return list<array{role: string, connection_id: int, connection_name: string}>
     */
    private function mappings(ServiceConnection $serviceConnection): array
    {
        return BazarrServiceLink::query()
            ->where('bazarr_connection_id', $serviceConnection->id)
            ->with('relatedConnection:id,name')
            ->orderBy('role')
            ->get()
            ->map(static fn (BazarrServiceLink $bazarrServiceLink): array => [
                'role' => $bazarrServiceLink->role->value,
                'connection_id' => $bazarrServiceLink->related_connection_id,
                'connection_name' => $bazarrServiceLink->relatedConnection->name,
            ])
            ->values()
            ->all();
    }
}
