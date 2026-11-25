<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams a stored upload (a chat or benchmark case attachment) so it can
 * never run on the app origin: raster images render inline, anything else
 * (PDF, text, and SVG should it ever slip past validation) is forced to
 * download, and both are sandboxed and never sniffed.
 */
final class StoredFileResponse
{
    public const array INLINE_MIME_TYPES = ['image/png', 'image/jpeg', 'image/webp', 'image/gif'];

    public static function make(string $disk, string $path, string $name, string $mimeType): StreamedResponse
    {
        $filesystem = Storage::disk($disk);

        abort_unless($filesystem->exists($path), 404);

        $headers = [
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ];

        return in_array($mimeType, self::INLINE_MIME_TYPES, true)
            ? $filesystem->response($path, $name, $headers)
            : $filesystem->download($path, $name, $headers);
    }
}
