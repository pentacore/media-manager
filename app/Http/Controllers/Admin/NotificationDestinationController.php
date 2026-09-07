<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\NotificationSeverity;
use App\Enums\PushChannelType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreNotificationDestinationRequest;
use App\Http\Requests\Admin\UpdateNotificationDestinationRequest;
use App\Models\NotificationDestination;
use App\Services\Notifications\PushFailureMessage;
use App\Services\Notifications\PushMessage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Throwable;

class NotificationDestinationController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Admin/NotificationDestinations/Index', [
            'destinations' => NotificationDestination::query()
                ->orderBy('label')
                ->get()
                ->map(fn (NotificationDestination $notificationDestination): array => [
                    'id' => $notificationDestination->id,
                    'channel' => $notificationDestination->channel->value,
                    'label' => $notificationDestination->label,
                    'is_enabled' => $notificationDestination->is_enabled,
                    'min_severity' => $notificationDestination->min_severity->value,
                    'config_hint' => $notificationDestination->configHint(),
                    // Non-secret fields are editable in place; secrets never leave the server.
                    'editable_config' => $this->editableConfig($notificationDestination),
                    'updated_at' => $notificationDestination->updated_at?->toIso8601String(),
                ])
                ->values()
                ->all(),
            'channels' => $this->availableChannels(),
            'severities' => NotificationSeverity::options(),
        ]);
    }

    public function store(StoreNotificationDestinationRequest $storeNotificationDestinationRequest): RedirectResponse
    {
        $validated = $storeNotificationDestinationRequest->validated();

        NotificationDestination::create([
            'channel' => $validated['channel'],
            'label' => $validated['label'],
            'is_enabled' => (bool) ($validated['is_enabled'] ?? true),
            'min_severity' => $validated['min_severity'],
            'config' => $this->cleanConfig(PushChannelType::from($validated['channel']), $validated['config']),
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Notification destination added.')]);

        return to_route('admin.notification-destinations.index');
    }

    public function update(UpdateNotificationDestinationRequest $updateNotificationDestinationRequest, NotificationDestination $notificationDestination): RedirectResponse
    {
        $validated = $updateNotificationDestinationRequest->validated();
        $type = PushChannelType::from($validated['channel']);
        $config = $this->cleanConfig($type, $validated['config']);

        // Blank encrypted fields mean "keep what is stored": the Discord URL
        // and the webhook secret are never prefilled in the edit dialog.
        if ($type === PushChannelType::Discord && $config['url'] === null) {
            $config['url'] = $notificationDestination->config['url'] ?? null;
        }

        if ($type === PushChannelType::Webhook && $config['secret'] === null) {
            $config['secret'] = $notificationDestination->config['secret'] ?? null;
        }

        $notificationDestination->update([
            'channel' => $type,
            'label' => $validated['label'],
            'is_enabled' => (bool) ($validated['is_enabled'] ?? true),
            'min_severity' => $validated['min_severity'],
            'config' => $config,
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Notification destination updated.')]);

        return to_route('admin.notification-destinations.index');
    }

    public function destroy(NotificationDestination $notificationDestination): RedirectResponse
    {
        $notificationDestination->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Notification destination removed.')]);

        return to_route('admin.notification-destinations.index');
    }

    /**
     * Push one message straight through the destination's channel so an admin
     * can verify the wiring without waiting for a real event.
     */
    public function test(NotificationDestination $notificationDestination): RedirectResponse
    {
        $channel = resolve($notificationDestination->channel->channelClass());
        $route = $notificationDestination->routeNotificationFor($notificationDestination->channel->value);

        try {
            if ($route === null || $route === '' || $route === []) {
                throw new RuntimeException('Destination has no target configured.');
            }

            $channel->deliver($route, new PushMessage(
                severity: 'info',
                title: __('MediaManager test notification'),
                body: __(':label is wired up correctly.', ['label' => $notificationDestination->label]),
                url: route('admin.notification-destinations.index'),
            ));
        } catch (Throwable $throwable) {
            Log::warning('Push test delivery failed', [
                'destination_id' => $notificationDestination->id,
                'channel' => $notificationDestination->channel->value,
                'exception' => $throwable::class,
                'message' => $throwable->getMessage(),
            ]);

            throw ValidationException::withMessages([
                'test' => __(':channel delivery failed: :error', [
                    'channel' => $channel->label(),
                    'error' => PushFailureMessage::for($throwable),
                ]),
            ]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Test notification sent.')]);

        return to_route('admin.notification-destinations.index');
    }

    /**
     * Only channels whose global prerequisites exist are offered.
     *
     * @return list<array{value: string, label: string}>
     */
    private function availableChannels(): array
    {
        $ntfy = is_string(config('services.ntfy.server')) && config('services.ntfy.server') !== '';
        $telegram = is_string(config('services.telegram.token')) && config('services.telegram.token') !== '';

        return array_values(array_filter(
            PushChannelType::options(),
            static fn (array $option): bool => match ($option['value']) {
                PushChannelType::Ntfy->value => $ntfy,
                PushChannelType::Telegram->value => $telegram,
                default => true,
            },
        ));
    }

    /**
     * Keep only the keys the channel knows, trimming blanks to null.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, string|null>
     */
    private function cleanConfig(PushChannelType $pushChannelType, array $config): array
    {
        $clean = [];

        foreach ($pushChannelType->configKeys() as $key) {
            $value = $config[$key] ?? null;
            $clean[$key] = is_string($value) && trim($value) !== '' ? trim($value) : null;
        }

        return $clean;
    }

    /**
     * Fields safe to prefill in the edit dialog (never Discord URLs or secrets).
     *
     * @return array<string, string|null>
     */
    private function editableConfig(NotificationDestination $notificationDestination): array
    {
        return match ($notificationDestination->channel) {
            PushChannelType::Ntfy => ['topic' => $notificationDestination->config['topic'] ?? null],
            PushChannelType::Telegram => ['chat_id' => $notificationDestination->config['chat_id'] ?? null],
            PushChannelType::Webhook => ['url' => $notificationDestination->config['url'] ?? null],
            PushChannelType::Discord => [],
        };
    }
}
