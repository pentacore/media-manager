<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\SettingsGroup;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateServerWatcherRequest;
use App\Services\Audit\AuditLogger;
use App\Services\Audit\SettingsSnapshot;
use App\Settings\MonitoringSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

/**
 * Saves or removes the outside watcher's push URL. A URL that comes from the environment cannot be removed here; the
 * request says so instead of pretending it worked.
 */
class ServerWatcherController extends Controller
{
    public function __invoke(
        UpdateServerWatcherRequest $updateServerWatcherRequest,
        MonitoringSettings $monitoringSettings,
        SettingsSnapshot $settingsSnapshot,
        AuditLogger $auditLogger,
    ): RedirectResponse {
        $validated = $updateServerWatcherRequest->validated();

        if ((bool) ($validated['clear'] ?? false) && $monitoringSettings->pushUrlSource() === MonitoringSettings::SOURCE_ENVIRONMENT) {
            Inertia::flash('toast', ['type' => 'info', 'message' => __('The push URL is set by MEDIAMANAGER_UPTIME_KUMA_PUSH_URL in the environment. Remove it there.')]);

            return back();
        }

        $before = $settingsSnapshot->capture(SettingsGroup::Monitoring);

        DB::transaction(function () use ($validated, $monitoringSettings, $settingsSnapshot, $auditLogger, $before): void {
            if ((bool) ($validated['clear'] ?? false)) {
                $monitoringSettings->setUptimeKumaPushUrl(null);
            } elseif (is_string($validated['push_url'] ?? null) && $validated['push_url'] !== '') {
                $monitoringSettings->setUptimeKumaPushUrl($validated['push_url']);
            }

            $auditLogger->settingsUpdated(SettingsGroup::Monitoring, $before, $settingsSnapshot->capture(SettingsGroup::Monitoring));
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Outside watcher saved.')]);

        return back();
    }
}
