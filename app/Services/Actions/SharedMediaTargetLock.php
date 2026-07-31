<?php

declare(strict_types=1);

namespace App\Services\Actions;

use InvalidArgumentException;

/**
 * Canonical lock key for a single installed media file, shared by every
 * executor that performs a file-affecting write against it. It binds the
 * pinned managing arr connection to a stable installed-file identity (media
 * type + arr media id), so a media replacement can never run concurrently with
 * a Bazarr subtitle download, deletion, synchronization, translation, or
 * modification for the same file.
 */
final readonly class SharedMediaTargetLock
{
    /**
     * The lease has to outlive the whole operation it guards, not just its first
     * upstream call: `ArrClient::getReleases()` alone may take 120 seconds, and a
     * replacement still has revalidation, the grab, deletion, blocklisting and
     * monitoring restoration after that. A cache lock expires on its own schedule,
     * so a shorter lease would let a second worker mutate the same installed file
     * while the first is still writing.
     *
     * Bounded above `ExecuteActionRequest`'s timeout (300 seconds), which is what
     * caps the work; the margin covers the queue's own retry_after handover.
     */
    public const int TTL_SECONDS = 900;

    public static function key(int $connectionId, string $mediaType, int $mediaId): string
    {
        throw_unless(
            in_array($mediaType, ['episode', 'movie'], true),
            InvalidArgumentException::class,
            'Shared media target lock media type must be episode or movie.',
        );
        throw_unless(
            $connectionId > 0 && $mediaId > 0,
            InvalidArgumentException::class,
            'Shared media target lock requires positive connection and media identifiers.',
        );

        return sprintf('media-target:%d:%s:%d', $connectionId, $mediaType, $mediaId);
    }
}
