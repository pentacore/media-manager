<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Throwable;

/**
 * Turns a push delivery failure into a message safe to show a user.
 * Connection errors carry the request URI (which for Telegram embeds the
 * bot token) and are summarised; HTTP errors are reduced to their status;
 * anything else is passed through with the Telegram token redacted.
 */
class PushFailureMessage
{
    public static function for(Throwable $throwable): string
    {
        if ($throwable instanceof ConnectionException) {
            return __('Could not reach the provider.');
        }

        if ($throwable instanceof RequestException) {
            return __('Provider responded with HTTP :status.', ['status' => $throwable->response->status()]);
        }

        $message = $throwable->getMessage();
        $token = config('services.telegram.token');

        if (is_string($token) && $token !== '') {
            $message = str_replace($token, '***', $message);
        }

        return $message;
    }
}
