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
use App\Services\Arr\ArrClient;
use App\Services\MediaReplacement\SonarrLibraryTypeSettings;
use App\Services\MediaReplacement\SonarrRootFolderCatalog;
use App\Services\MediaReplacement\SubtitleCheckTagSettings;
use App\Services\Prowlarr\ProwlarrClient;
use App\Services\Radarr\RadarrClient;
use App\Services\ServiceClientFactory;
use App\Services\Sonarr\SonarrClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
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
        $validated = $serviceConnectionStoreRequest->validated();
        $validated = $this->mergeWhisparrVersion($validated);

        ServiceConnection::create($validated);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Connection created.')]);

        return to_route('admin.connections.index');
    }

    public function edit(
        ServiceConnection $serviceConnection,
        SonarrRootFolderCatalog $sonarrRootFolderCatalog,
        SubtitleCheckTagSettings $subtitleCheckTagSettings,
    ): Response {
        $diskSettings = is_array($serviceConnection->settings['disk'] ?? null)
            ? $serviceConnection->settings['disk']
            : ['mode' => 'all', 'paths' => [], 'display' => []];

        return Inertia::render('Admin/Connections/Edit', [
            'connection' => [
                'id' => $serviceConnection->id,
                'type' => $serviceConnection->type,
                'name' => $serviceConnection->name,
                'url' => $serviceConnection->url,
                'external_url' => $serviceConnection->external_url,
                'api_key_set' => $serviceConnection->api_key !== '' && $serviceConnection->api_key !== null,
                'webhook_token_set' => $serviceConnection->webhook_token !== '' && $serviceConnection->webhook_token !== null,
                'webhook_url' => $this->webhookUrlFor($serviceConnection),
                'supports_webhook_configuration' => $serviceConnection->type->supportsWebhookConfiguration(),
                'is_active' => $serviceConnection->is_active,
                'disk' => [
                    'mode' => $diskSettings['mode'] ?? 'all',
                    'paths' => array_values($diskSettings['paths'] ?? []),
                    'display' => is_array($diskSettings['display'] ?? null)
                        ? $diskSettings['display']
                        : [],
                ],
                'hidden_categories' => is_array($serviceConnection->settings['hidden_categories'] ?? null)
                    ? array_values($serviceConnection->settings['hidden_categories'])
                    : [],
                'sabnzbd_webhook_script' => $serviceConnection->type === ServiceType::SABnzbd
                    ? $this->sabnzbdNotificationScriptFor($serviceConnection)
                    : null,
                'whisparr_version' => $serviceConnection->whisparrVersion()->value,
            ],
            'serviceTypes' => ServiceType::mapForSelect(labelKey: 'label'),
            'indexers' => $serviceConnection->type === ServiceType::Prowlarr
                ? Inertia::defer(fn (): array => $this->loadProwlarrIndexers($serviceConnection))
                : [],
            'availableDiskPaths' => in_array($serviceConnection->type, [ServiceType::Sonarr, ServiceType::Radarr], true)
                ? Inertia::defer(fn (): array => $this->loadAvailableDiskPaths($serviceConnection))
                : [],
            'sonarrRootFolders' => $serviceConnection->type === ServiceType::Sonarr
                ? Inertia::defer(fn (): array => $sonarrRootFolderCatalog->forConnection($serviceConnection))
                : [],
            'arrTags' => in_array($serviceConnection->type, [ServiceType::Sonarr, ServiceType::Radarr], true)
                ? Inertia::defer(fn (): ?array => $this->arrTags($serviceConnection))
                : null,
            'subtitleCheckTags' => $subtitleCheckTagSettings->forConnection($serviceConnection),
        ]);
    }

    /**
     * Tag labels defined on a Sonarr/Radarr instance, for the subtitle-check
     * picker. Returns null for a disabled connection and when the instance
     * cannot be reached — so the form can tell "none defined" (an empty list)
     * apart from "unavailable" (null). Rows the instance reports without a
     * usable int id and a non-blank label are dropped.
     *
     * Caller must restrict this to Sonarr and Radarr connections; edit() owns
     * that check, because it also decides whether to defer the prop at all.
     *
     * @return list<array{id: int, label: string}>|null
     */
    private function arrTags(ServiceConnection $serviceConnection): ?array
    {
        if (! $serviceConnection->is_active) {
            return null;
        }

        try {
            $tags = $serviceConnection->type === ServiceType::Sonarr
                ? new SonarrClient($serviceConnection)->getTags()
                : new RadarrClient($serviceConnection)->getTags();
        } catch (Throwable) {
            return null;
        }

        $labels = [];

        foreach ($tags as $tag) {
            if (! is_array($tag)) {
                continue;
            }

            $id = $tag['id'] ?? null;
            $label = $tag['label'] ?? null;

            if (! is_int($id) || ! is_string($label) || trim($label) === '') {
                continue;
            }

            $labels[] = ['id' => $id, 'label' => $label];
        }

        return $labels;
    }

    /**
     * Fold the flat `whisparr_version` field into the settings JSON. Leaves
     * non-Whisparr payloads untouched.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function mergeWhisparrVersion(array $validated, ?ServiceConnection $serviceConnection = null): array
    {
        $version = $validated['whisparr_version'] ?? null;
        unset($validated['whisparr_version']);

        if (! is_string($version) || $version === '') {
            return $validated;
        }

        $existingSettings = $validated['settings'] ?? $serviceConnection?->settings ?? [];
        $existingSettings['whisparr_version'] = $version;
        $validated['settings'] = $existingSettings;

        return $validated;
    }

    /**
     * SABnzbd has no native HTTP webhook; it does support notification
     * scripts that get exec'd with SAB_* env vars. We render a copy-
     * pastable Python 3 script (stdlib only) that admins drop into
     * SABnzbd's `scripts/` folder and select under Notifications. The
     * URL embeds the per-connection token, so the script needs no
     * configuration of its own.
     */
    private function sabnzbdNotificationScriptFor(ServiceConnection $serviceConnection): string
    {
        $url = $this->webhookUrlFor($serviceConnection);

        return <<<PYTHON
            #!/usr/bin/env python3
            # MediaManager SABnzbd notification script.
            # Drop into SAB's scripts folder, then pick it under
            # Settings → Notifications → "Run script".
            import json
            import os
            import sys
            import urllib.request

            WEBHOOK_URL = "{$url}"

            payload = {
                "eventType": os.environ.get("SAB_NOTIFICATION_TYPE", ""),
                "title": os.environ.get("SAB_TITLE", ""),
                "message": os.environ.get("SAB_MSG", ""),
                "hostname": os.environ.get("SAB_HOSTNAME", ""),
                "version": os.environ.get("SAB_VERSION", ""),
                "category": os.environ.get("SAB_CAT", ""),
                "name": os.environ.get("SAB_NAME", ""),
            }

            req = urllib.request.Request(
                WEBHOOK_URL,
                data=json.dumps(payload).encode("utf-8"),
                headers={"Content-Type": "application/json"},
                method="POST",
            )

            try:
                with urllib.request.urlopen(req, timeout=5) as resp:
                    sys.exit(0 if 200 <= resp.status < 300 else 1)
            except Exception as exc:
                print(f"MediaManager webhook delivery failed: {exc}", file=sys.stderr)
                sys.exit(1)
            PYTHON;
    }

    /**
     * Build the URL the upstream service should POST to. Used in the
     * connection edit page so admins can copy/paste it into the
     * Sonarr/Radarr/etc. webhook setup screens. Includes the
     * ?token=… fallback for services that can't set custom headers.
     */
    private function webhookUrlFor(ServiceConnection $serviceConnection): string
    {
        $base = route('webhooks.handle', [
            'service' => $serviceConnection->type->value,
            'connection' => $serviceConnection->id,
        ]);

        $token = $serviceConnection->webhook_token;

        return is_string($token) && $token !== ''
            ? $base.'?token='.urlencode($token)
            : $base;
    }

    /**
     * @return array<int, array{path: string, label: string|null}>
     */
    private function loadAvailableDiskPaths(ServiceConnection $serviceConnection): array
    {
        if (! $serviceConnection->is_active) {
            return [];
        }

        try {
            $client = resolve(ServiceClientFactory::class)->make($serviceConnection);

            if (! method_exists($client, 'getDiskSpace')) {
                return [];
            }

            $entries = $client->getDiskSpace();
        } catch (Throwable $throwable) {
            Log::warning('Failed to load disk paths for connection edit page', [
                'connection_id' => $serviceConnection->id,
                'exception' => $throwable::class,
            ]);

            return [];
        }

        return array_values(array_filter(array_map(static fn (array $entry): ?array => isset($entry['path']) && is_string($entry['path'])
                ? ['path' => $entry['path'], 'label' => $entry['label'] ?? null]
                : null, $entries)));
    }

    /**
     * @return array<int, array{id: int|null, name: string|null, enable: bool, priority: int|null, implementation: string|null}>
     */
    private function loadProwlarrIndexers(ServiceConnection $serviceConnection): array
    {
        try {
            $entries = new ProwlarrClient($serviceConnection)->listIndexers();

            return array_map(fn (array $entry): array => [
                'id' => $entry['id'] ?? null,
                'name' => $entry['name'] ?? null,
                'enable' => $entry['enable'] ?? false,
                'priority' => $entry['priority'] ?? null,
                'implementation' => $entry['implementation'] ?? null,
            ], $entries);
        } catch (Throwable $throwable) {
            Log::warning('Failed to load Prowlarr indexers for edit page', [
                'connection_id' => $serviceConnection->id,
                'exception' => $throwable::class,
            ]);

            return [];
        }
    }

    public function update(
        ServiceConnectionUpdateRequest $serviceConnectionUpdateRequest,
        ServiceConnection $serviceConnection,
        SonarrLibraryTypeSettings $sonarrLibraryTypeSettings,
        SubtitleCheckTagSettings $subtitleCheckTagSettings,
    ): RedirectResponse {
        $validated = $serviceConnectionUpdateRequest->validated();

        // Do not wipe existing secrets when the admin submits the form without
        // retyping them (blank/null means "keep existing value").
        foreach (['api_key', 'webhook_token'] as $secretField) {
            $value = $validated[$secretField] ?? null;

            if (! is_string($value) || trim($value) === '') {
                unset($validated[$secretField]);
            }
        }

        // Pull disk preferences out of the flat payload and merge them
        // into the connection's settings JSON so the rest of the update
        // path stays unaware of them.
        $diskMode = $validated['disk_mode'] ?? null;
        $diskPaths = $validated['disk_paths'] ?? null;
        $diskDisplay = $validated['disk_display'] ?? null;
        $hiddenCategories = $validated['hidden_categories'] ?? null;
        $sonarrRootFolders = $validated['sonarr_root_folders'] ?? null;
        $subtitleCheckTags = $validated['subtitle_check_tags'] ?? null;
        unset(
            $validated['disk_mode'],
            $validated['disk_paths'],
            $validated['disk_display'],
            $validated['hidden_categories'],
            $validated['sonarr_root_folders'],
            $validated['subtitle_check_tags'],
        );

        if ($diskMode !== null || $diskPaths !== null || $diskDisplay !== null) {
            $existingSettings = $validated['settings'] ?? $serviceConnection->settings ?? [];
            $existingSettings['disk'] = [
                'mode' => $diskMode ?? 'all',
                'paths' => array_values(array_filter(
                    $diskPaths ?? [],
                    static fn (mixed $path): bool => is_string($path) && trim($path) !== '',
                )),
                'display' => is_array($diskDisplay) ? $diskDisplay : [],
            ];
            $validated['settings'] = $existingSettings;
        }

        if ($hiddenCategories !== null && $serviceConnection->type === ServiceType::SABnzbd) {
            $existingSettings = $validated['settings'] ?? $serviceConnection->settings ?? [];
            $existingSettings['hidden_categories'] = array_values(array_filter(
                $hiddenCategories,
                static fn (mixed $category): bool => is_string($category) && trim($category) !== '',
            ));
            $validated['settings'] = $existingSettings;
        }

        if (is_array($sonarrRootFolders) && $serviceConnection->type === ServiceType::Sonarr) {
            $existingSettings = $validated['settings'] ?? $serviceConnection->settings ?? [];
            $validated['settings'] = $sonarrLibraryTypeSettings->mergeInto($existingSettings, $sonarrRootFolders);
        }

        if (is_array($subtitleCheckTags) && in_array($serviceConnection->type, [ServiceType::Sonarr, ServiceType::Radarr], true)) {
            $existingSettings = is_array($validated['settings'] ?? null)
                ? $validated['settings']
                : (is_array($serviceConnection->settings) ? $serviceConnection->settings : []);
            $validated['settings'] = $subtitleCheckTagSettings->mergeInto($existingSettings, $subtitleCheckTags);
        }

        $validated = $this->mergeWhisparrVersion($validated, $serviceConnection);

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
                ServiceType::SABnzbd => $client->getVersion(),
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

    public function configureWebhook(ServiceConnection $serviceConnection): RedirectResponse
    {
        if (! $serviceConnection->type->supportsWebhookConfiguration()) {
            Inertia::flash('toast', [
                'type' => 'error',
                'message' => __(':type does not support automatic webhook configuration.', [
                    'type' => $serviceConnection->type->label(),
                ]),
            ]);

            return back()->withErrors([
                'configure_webhook' => 'Service type does not support automatic webhook configuration.',
            ]);
        }

        try {
            $client = resolve(ServiceClientFactory::class)->make($serviceConnection);

            throw_unless($client instanceof ArrClient, RuntimeException::class, 'Resolved client is not an ArrClient instance.');

            $callbackUrl = $this->webhookUrlFor($serviceConnection);
            $client->configureWebhook($callbackUrl);
        } catch (Throwable $throwable) {
            Log::warning('Failed to configure webhook on upstream service', [
                'connection_id' => $serviceConnection->id,
                'exception' => $throwable::class,
                'message' => $throwable->getMessage(),
            ]);

            Inertia::flash('toast', [
                'type' => 'error',
                'message' => __('Failed to configure webhook: :error', ['error' => $throwable->getMessage()]),
            ]);

            return back()->withErrors([
                'configure_webhook' => $throwable->getMessage(),
            ]);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Webhook configured on :name.', ['name' => $serviceConnection->name]),
        ]);

        return back()->with('success', true);
    }
}
