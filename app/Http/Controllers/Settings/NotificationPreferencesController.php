<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\NotificationPreference;
use App\Notifications\AiBudgetSoftLimitReached;
use App\Notifications\ServiceWarning;
use App\Services\Notifications\PreferenceResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class NotificationPreferencesController extends Controller
{
    /**
     * The notifications surfaced on the preferences page. Adding a new
     * notification class is a 2-line change here — keys are the FQCN,
     * values describe the row in the UI.
     */
    private const array CATALOG = [
        ServiceWarning::class => [
            'label' => 'Service warning',
            'description' => 'Health and disk-full events from Sonarr / Radarr / Prowlarr / SABnzbd webhooks.',
        ],
        AiBudgetSoftLimitReached::class => [
            'label' => 'AI soft budget limit reached',
            'description' => 'Heads-up when monthly AI spend crosses the soft cap.',
        ],
    ];

    public function edit(Request $request): Response
    {
        $user = $request->user();
        $rows = NotificationPreference::query()
            ->where('user_id', $user->id)
            ->get()
            ->keyBy(fn (NotificationPreference $notificationPreference): string => $notificationPreference->notification_class.'|'.$notificationPreference->severity);

        $catalog = [];
        foreach (self::CATALOG as $class => $meta) {
            $perSeverity = [];
            foreach (PreferenceResolver::SEVERITIES as $severity) {
                $row = $rows[$class.'|'.$severity] ?? null;

                $perSeverity[$severity] = [
                    'database' => $row?->database ?? true,
                    'broadcast' => $row?->broadcast ?? true,
                    'mail' => $row?->mail ?? false,
                    'ntfy' => $row?->ntfy ?? false,
                ];
            }

            $catalog[] = [
                'class' => $class,
                'label' => $meta['label'],
                'description' => $meta['description'],
                'severities' => $perSeverity,
            ];
        }

        return Inertia::render('settings/Notifications', [
            'catalog' => $catalog,
            'channels' => PreferenceResolver::CHANNELS,
            'severities' => PreferenceResolver::SEVERITIES,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'preferences' => ['required', 'array'],
            'preferences.*.class' => ['required', 'string'],
            'preferences.*.severities' => ['required', 'array'],
            'preferences.*.severities.*.database' => ['boolean'],
            'preferences.*.severities.*.broadcast' => ['boolean'],
            'preferences.*.severities.*.mail' => ['boolean'],
            'preferences.*.severities.*.ntfy' => ['boolean'],
        ]);

        $user = $request->user();

        foreach ($validated['preferences'] as $entry) {
            if (! array_key_exists((string) $entry['class'], self::CATALOG)) {
                continue;
            }

            foreach ($entry['severities'] as $severity => $flags) {
                if (! in_array($severity, PreferenceResolver::SEVERITIES, true)) {
                    continue;
                }

                NotificationPreference::query()->updateOrCreate(
                    [
                        'user_id' => $user->id,
                        'notification_class' => $entry['class'],
                        'severity' => $severity,
                    ],
                    [
                        'database' => (bool) ($flags['database'] ?? true),
                        'broadcast' => (bool) ($flags['broadcast'] ?? true),
                        'mail' => (bool) ($flags['mail'] ?? false),
                        'ntfy' => (bool) ($flags['ntfy'] ?? false),
                    ],
                );
            }
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Notification preferences saved.')]);

        return back();
    }
}
