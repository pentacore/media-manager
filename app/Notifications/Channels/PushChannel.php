<?php

declare(strict_types=1);

namespace App\Notifications\Channels;

use App\Services\Notifications\PushMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Base for every push channel. Resolves the notifiable's route for the
 * channel driver, asks the notification for its PushMessage and hands both
 * to deliver(). Delivery is best-effort: failures are logged and swallowed
 * so webhook handlers and jobs never break on a third-party outage.
 * deliver() itself throws, so a caller that needs the failure surfaced can
 * invoke it directly instead of going through send().
 */
abstract class PushChannel
{
    /** Driver name Laravel uses for routeNotificationFor{Driver}(). */
    public const string DRIVER = '';

    public function send(object $notifiable, Notification $notification): void
    {
        if (static::DRIVER === '') {
            return;
        }

        if (! method_exists($notifiable, 'routeNotificationFor') || ! method_exists($notification, 'toPush')) {
            return;
        }

        try {
            $route = $notifiable->routeNotificationFor(static::DRIVER, $notification);

            if (in_array($route, [null, '', []], true)) {
                return;
            }

            $this->deliver($route, $notification->toPush($notifiable));
        } catch (Throwable $throwable) {
            Log::warning(sprintf('%s delivery failed', $this->label()), [
                'notification' => $notification::class,
                'exception' => $throwable::class,
                'message' => $throwable->getMessage(),
            ]);
        }
    }

    /**
     * Push one message to a concrete destination. Throws on failure.
     */
    abstract public function deliver(mixed $route, PushMessage $message): void;

    /** Human label used in log lines and validation messages. */
    abstract public function label(): string;
}
