<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Enums\PushChannelType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\TestNotificationChannelRequest;
use App\Http\Requests\Settings\UpdateNotificationPreferencesRequest;
use App\Models\NotificationPreference;
use App\Notifications\AiBudgetSoftLimitReached;
use App\Notifications\DecisionAgentActed;
use App\Notifications\MediaReplacementStatusChanged;
use App\Notifications\ServiceUpdateAvailable;
use App\Notifications\ServiceWarning;
use App\Notifications\SubtitleCaseNeedsReview;
use App\Services\Notifications\PreferenceResolver;
use App\Services\Notifications\PushMessage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

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
        MediaReplacementStatusChanged::class => [
            'label' => 'Subtitle replacement status',
            'description' => 'When a subtitle replacement is verified, fails, or needs manual attention.',
        ],
        DecisionAgentActed::class => [
            'label' => 'Decision agent activity',
            'description' => 'When the decision agent takes or proposes an action on your library.',
        ],
        SubtitleCaseNeedsReview::class => [
            'label' => 'Subtitle case needs review',
            'description' => 'When a subtitle case is escalated and needs a human decision.',
        ],
    ];

    public function edit(Request $request): Response
    {
        $user = $request->user();
        $rows = NotificationPreference::query()
            ->where('user_id', $user->id)
            ->get()
            ->keyBy(fn (NotificationPreference $notificationPreference): string => $notificationPreference->notification_class.'|'.$notificationPreference->severity);

        $preferenceResolver = resolve(PreferenceResolver::class);

        $catalog = [];
        foreach (self::CATALOG as $class => $meta) {
            $defaults = $preferenceResolver->defaultsFor($class);
            $perSeverity = [];
            foreach (PreferenceResolver::SEVERITIES as $severity) {
                $row = $rows[$class.'|'.$severity] ?? null;
                $flags = [];
                foreach (PreferenceResolver::CHANNELS as $channel) {
                    $flags[$channel] = $row?->{$channel} ?? $defaults[$channel];
                }
                $perSeverity[$severity] = $flags;
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
            'destinations' => [
                'ntfy_topic' => $user->ntfy_topic,
                'discord_webhook_url_hint' => self::hint($user->discord_webhook_url),
                'telegram_chat_id' => $user->telegram_chat_id,
                'webhook_url' => $user->webhook_url,
                'webhook_secret_set' => is_string($user->webhook_secret) && $user->webhook_secret !== '',
            ],
            'channelsConfigured' => [
                'ntfy' => is_string(config('services.ntfy.server')) && config('services.ntfy.server') !== '',
                'telegram' => is_string(config('services.telegram.token')) && config('services.telegram.token') !== '',
            ],
        ]);
    }

    public function update(UpdateNotificationPreferencesRequest $updateNotificationPreferencesRequest): RedirectResponse
    {
        $validated = $updateNotificationPreferencesRequest->validated();
        $user = $updateNotificationPreferencesRequest->user();

        foreach ($validated['preferences'] as $entry) {
            if (! array_key_exists((string) $entry['class'], self::CATALOG)) {
                continue;
            }

            foreach ($entry['severities'] as $severity => $flags) {
                if (! in_array($severity, PreferenceResolver::SEVERITIES, true)) {
                    continue;
                }

                $defaults = resolve(PreferenceResolver::class)->defaultsFor($entry['class']);
                $values = [];
                foreach (PreferenceResolver::CHANNELS as $channel) {
                    $values[$channel] = (bool) ($flags[$channel] ?? $defaults[$channel]);
                }

                NotificationPreference::query()->updateOrCreate(
                    ['user_id' => $user->id, 'notification_class' => $entry['class'], 'severity' => $severity],
                    $values,
                );
            }
        }

        $attributes = [
            'ntfy_topic' => self::blankToNull($validated['ntfy_topic'] ?? null),
            'telegram_chat_id' => self::blankToNull($validated['telegram_chat_id'] ?? null),
            'webhook_url' => self::blankToNull($validated['webhook_url'] ?? null),
        ];

        // Secrets are only touched when the key was submitted ('' clears).
        foreach (['discord_webhook_url', 'webhook_secret'] as $secret) {
            if (array_key_exists($secret, $validated)) {
                $attributes[$secret] = self::blankToNull($validated[$secret]);
            }
        }

        $user->update($attributes);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Notification preferences saved.')]);

        return back();
    }

    /**
     * Push a test message through one channel to the current user's own
     * destination so they can verify wiring without waiting for a real event.
     */
    public function test(TestNotificationChannelRequest $testNotificationChannelRequest): RedirectResponse
    {
        $validated = $testNotificationChannelRequest->validated();
        $user = $testNotificationChannelRequest->user();
        $type = PushChannelType::from($validated['channel']);
        $channel = resolve($type->channelClass());
        $route = $user->routeNotificationFor($type->value);

        if ($route === null || $route === '' || $route === []) {
            throw ValidationException::withMessages([
                'test_channel' => __('Set and save a :channel destination first.', ['channel' => $channel->label()]),
            ]);
        }

        try {
            $channel->deliver($route, new PushMessage(
                severity: 'info',
                title: __('MediaManager test notification'),
                body: __(':channel is wired up correctly.', ['channel' => $channel->label()]),
                url: route('settings.notifications.edit'),
            ));
        } catch (Throwable $throwable) {
            throw ValidationException::withMessages([
                'test_channel' => __(':channel delivery failed: :error', ['channel' => $channel->label(), 'error' => $throwable->getMessage()]),
            ]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Test notification sent.')]);

        return back();
    }

    private static function hint(?string $secret): ?string
    {
        return is_string($secret) && $secret !== '' ? '…'.Str::substr($secret, -4) : null;
    }

    private static function blankToNull(?string $value): ?string
    {
        return $value === null || trim($value) === '' ? null : trim($value);
    }
}
