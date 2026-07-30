<?php

declare(strict_types=1);

namespace App\Support;

class UpstreamErrorText
{
    /**
     * Reduce an upstream error message to something safe to persist or log.
     *
     * Client exception messages embed whatever the remote service said, which
     * for the arr and Bazarr APIs routinely means absolute library paths and —
     * via Guzzle's effective URI — query strings carrying credentials. Case
     * failure reasons are shown to operators and kept indefinitely, so the text
     * has to lose both before it is stored.
     */
    public static function sanitize(string $message, int $limit = 300): string
    {
        // Upstream JSON bodies arrive with escaped separators; unescape first so
        // the path pattern below sees them.
        $message = str_replace('\/', '/', $message);
        $message = UrlQueryRedactor::redact($message);
        $message = preg_replace(
            '#(?<![\w:])/(?:[\w.\-+@%~]+/)*[\w.\-+@%~]+#',
            '[redacted path]',
            $message,
        ) ?? $message;
        $message = trim((string) preg_replace('/\s+/', ' ', $message));

        return $message === ''
            ? 'The upstream service returned an error without a usable description.'
            : mb_substr($message, 0, $limit);
    }
}
