<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\StorePushSubscriptionRequest;
use App\Models\PushSubscription;
use App\Notifications\Channels\WebPushChannel;
use App\Services\Notifications\PushFailureMessage;
use App\Services\Notifications\PushMessage;
use App\Services\Notifications\WebPushConfig;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Throwable;

class PushSubscriptionController extends Controller
{
    /**
     * Register this browser for the caller. An endpoint is unique per browser
     * profile, so signing in as someone else moves the row to that user.
     */
    public function store(StorePushSubscriptionRequest $storePushSubscriptionRequest): RedirectResponse
    {
        $config = resolve(WebPushConfig::class);

        if (! $config->available()) {
            throw ValidationException::withMessages([
                'endpoint' => __('Web push is not configured on this server.'),
            ]);
        }

        $validated = $storePushSubscriptionRequest->validated();

        PushSubscription::query()->updateOrCreate(
            ['endpoint' => $validated['endpoint']],
            [
                'user_id' => $storePushSubscriptionRequest->user()->id,
                'public_key' => $validated['keys']['p256dh'],
                'auth_token' => $validated['keys']['auth'],
                'content_encoding' => $validated['contentEncoding'] ?? 'aes128gcm',
                'vapid_public_key' => $config->publicKey(),
                'label' => $this->label((string) $storePushSubscriptionRequest->userAgent()),
            ],
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('This device will now receive web push notifications.')]);

        return back();
    }

    public function destroy(PushSubscription $pushSubscription): RedirectResponse
    {
        Gate::authorize('delete', $pushSubscription);

        $pushSubscription->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Device removed.')]);

        return back();
    }

    /**
     * Push a test message to every device of the caller, synchronously.
     */
    public function test(Request $request, WebPushChannel $webPushChannel): RedirectResponse
    {
        $user = $request->user();

        if (! resolve(WebPushConfig::class)->available()) {
            throw ValidationException::withMessages([
                'test_push' => __('Web push is not configured on this server.'),
            ]);
        }

        $route = $user->routeNotificationForWebpush();

        if ($route === null) {
            throw ValidationException::withMessages([
                'test_push' => __('Enable web push on a device first.'),
            ]);
        }

        try {
            $webPushChannel->deliver($route, new PushMessage(
                severity: 'info',
                title: __('Test push'),
                body: __('Web push works on this device.'),
                url: route('settings.notifications.edit'),
            ));
        } catch (Throwable $throwable) {
            Log::warning('Web push test delivery failed', [
                'user_id' => $user->id,
                'exception' => $throwable::class,
                'message' => $throwable->getMessage(),
            ]);

            throw ValidationException::withMessages([
                'test_push' => __('Web push delivery failed: :error', ['error' => PushFailureMessage::for($throwable)]),
            ]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Test push sent.')]);

        return back();
    }

    /**
     * A short device name such as "Chrome on Android" from the User-Agent.
     */
    private function label(string $userAgent): ?string
    {
        $browser = match (true) {
            str_contains($userAgent, 'Edg') => 'Edge',
            str_contains($userAgent, 'OPR/') => 'Opera',
            str_contains($userAgent, 'Firefox') || str_contains($userAgent, 'FxiOS') => 'Firefox',
            str_contains($userAgent, 'Chrome') || str_contains($userAgent, 'CriOS') => 'Chrome',
            str_contains($userAgent, 'Safari') => 'Safari',
            default => null,
        };

        $platform = match (true) {
            str_contains($userAgent, 'Android') => 'Android',
            str_contains($userAgent, 'iPhone') || str_contains($userAgent, 'iPad') => 'iOS',
            str_contains($userAgent, 'Windows') => 'Windows',
            str_contains($userAgent, 'Mac OS X') => 'macOS',
            str_contains($userAgent, 'Linux') => 'Linux',
            default => null,
        };

        return match (true) {
            $browser !== null && $platform !== null => $browser.' on '.$platform,
            default => $browser ?? $platform,
        };
    }
}
