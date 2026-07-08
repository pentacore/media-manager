<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\NotificationPreference;
use App\Notifications\AiBudgetSoftLimitReached;
use App\Notifications\ServiceUpdateAvailable;
use App\Notifications\ServiceWarning;
use App\Services\Notifications\NtfyMessage;
use App\Services\Notifications\PreferenceResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
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
        ServiceUpdateAvailable::class => [
            'label' => 'Service update available',
            'description' => 'A newer release was found for one of your connected services.',
        ],
    ];

    public function edit(Request $request): Response
    {
        $user = $request->user();
        $rows = NotificationPreference::query()
            ->where('user_id', $user->id)
            ->get()
            ->keyBy(fn (NotificationPreference $notificationPreference): string => $notificationPreference->notification_class.'|'.$notificationPreference->severity);

        $resolver = resolve(PreferenceResolver::class);

        $catalog = [];
        foreach (self::CATALOG as $class => $meta) {
            $defaults = $resolver->defaultsFor($class);
            $perSeverity = [];
            foreach (PreferenceResolver::SEVERITIES as $severity) {
                $row = $rows[$class.'|'.$severity] ?? null;

                $perSeverity[$severity] = [
                    'database' => $row?->database ?? $defaults['database'],
                    'broadcast' => $row?->broadcast ?? $defaults['broadcast'],
                    'mail' => $row?->mail ?? $defaults['mail'],
                    'ntfy' => $row?->ntfy ?? $defaults['ntfy'],
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
            'ntfyTopic' => $user->ntfy_topic,
            'ntfyConfigured' => is_string(config('services.ntfy.server')) && config('services.ntfy.server') !== '',
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'preferences' => ['present', 'array'],
            'preferences.*.class' => ['required', 'string'],
            'preferences.*.severities' => ['required', 'array'],
            'preferences.*.severities.*.database' => ['boolean'],
            'preferences.*.severities.*.broadcast' => ['boolean'],
            'preferences.*.severities.*.mail' => ['boolean'],
            'preferences.*.severities.*.ntfy' => ['boolean'],
            'ntfy_topic' => ['nullable', 'string', 'max:255', 'regex:/^[-_A-Za-z0-9]+$/'],
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

        $user->update(['ntfy_topic' => $validated['ntfy_topic'] ?? null]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Notification preferences saved.')]);

        return back();
    }

    /**
     * Push a test message to the current user's ntfy topic so they can
     * verify server/topic wiring without waiting for a real event.
     */
    public function test(Request $request): RedirectResponse
    {
        $user = $request->user();
        $topic = $user->ntfy_topic;

        if (! is_string($topic) || $topic === '') {
            throw ValidationException::withMessages([
                'ntfy_topic' => __('Set and save an ntfy topic first.'),
            ]);
        }

        $payload = [
            ...NtfyMessage::for('info', __('MediaManager test notification'), __('Ntfy is wired up correctly.'), route('settings.notifications.edit')),
            'topic' => $topic,
        ];

        try {
            $ntfyRequest = Http::timeout(5);
            $token = config('services.ntfy.token');

            if (is_string($token) && $token !== '') {
                $ntfyRequest = $ntfyRequest->withToken($token);
            }

            $ntfyRequest->post((string) config('services.ntfy.server'), $payload)->throw();
        } catch (\Throwable $throwable) {
            throw ValidationException::withMessages([
                'ntfy_topic' => __('Ntfy delivery failed: :error', ['error' => $throwable->getMessage()]),
            ]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Test notification sent.')]);

        return back();
    }
}
