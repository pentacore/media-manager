<?php

declare(strict_types=1);

namespace App\Http\Controllers\Media;

use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * The redirect after a Seerr request filed from a title card (Anime,
 * Discover): the toast, plus a `requestOutcome` flash the card reads to show
 * "requested" or to roll back. A failure still redirects (a successful
 * Inertia visit), so the outcome is always explicit.
 */
trait FlashesRequestOutcome
{
    protected function requestOutcome(bool $ok, int $tmdbId, string $mediaType, string $type, string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => $type, 'message' => $message]);
        Inertia::flash('requestOutcome', ['ok' => $ok, 'tmdbId' => $tmdbId, 'mediaType' => $mediaType]);

        return back();
    }
}
