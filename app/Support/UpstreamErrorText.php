<?php

declare(strict_types=1);

namespace App\Support;

class UpstreamErrorText
{
    /** What sanitize() returns for an upstream error that carries no usable text. */
    public const string UPSTREAM_EMPTY_FALLBACK = 'The upstream service returned an error without a usable description.';

    /**
     * The empty-message fallback for agent runs: the failure belongs to the
     * agent (provider, tool loop or local code), not to a media service.
     */
    public const string AGENT_EMPTY_FALLBACK = 'The agent failed without a usable description.';

    /**
     * The empty-message fallback for a queued action that failed on a local
     * exception (an executor bug, a lost or timed-out job), not on a media
     * service's answer.
     */
    public const string ACTION_EMPTY_FALLBACK = 'The action failed without a usable description.';

    /**
     * Reduce an upstream error message to something safe to persist or log.
     *
     * Client exception messages embed whatever the remote service said, which
     * for the arr and Bazarr APIs routinely means absolute library paths and —
     * via Guzzle's effective URI — query strings carrying credentials. Case
     * failure reasons are shown to operators and kept indefinitely, so the text
     * has to lose both before it is stored. A message with nothing left
     * becomes $emptyFallback, which callers outside the media integrations set
     * so the sentence names the right culprit.
     */
    public static function sanitize(string $message, int $limit = 300, string $emptyFallback = self::UPSTREAM_EMPTY_FALLBACK): string
    {
        // Upstream JSON bodies arrive with escaped separators; unescape first so
        // the path pattern below sees them.
        $message = str_replace('\/', '/', $message);
        $message = UrlQueryRedactor::redact($message);
        // A URL keeps its scheme and host (the slashes after "scheme:" and the
        // ones inside the URL are not path starts), so an operator still sees
        // which server failed; its query string is already redacted above.
        $message = preg_replace(
            '#(?<![\w:/])/(?:[\w.\-+@%~]+/)*[\w.\-+@%~]+#',
            '[redacted path]',
            $message,
        ) ?? $message;
        // Windows drive-letter and UNC paths (arr instances on Windows hosts).
        $message = preg_replace(
            '#(?<!\w)(?:[A-Za-z]:\\\\|\\\\\\\\)[^\s"\'<>|]+#',
            '[redacted path]',
            $message,
        ) ?? $message;
        $message = trim((string) preg_replace('/\s+/', ' ', $message));

        return $message === '' ? $emptyFallback : mb_substr($message, 0, $limit);
    }
}
