<?php

declare(strict_types=1);

namespace App\Support;

class UrlQueryRedactor
{
    /**
     * Strip query strings from every URL inside a free-form message.
     *
     * Exception messages from HTTP clients (Guzzle ConnectException in
     * particular) embed the full effective URI — including query strings that
     * can carry credentials such as SABnzbd's mandatory `apikey` parameter.
     * Anything persisted, broadcast, or logged from such a message must pass
     * through here first.
     */
    public static function redact(string $message): string
    {
        return preg_replace(
            '/(https?:\/\/[^\s"\'<>?]+)\?[^\s"\'<>]*/i',
            '$1?[redacted]',
            $message,
        ) ?? $message;
    }
}
