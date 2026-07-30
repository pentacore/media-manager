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
    public const int TTL_SECONDS = 120;

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
