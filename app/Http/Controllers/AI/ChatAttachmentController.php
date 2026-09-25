<?php

declare(strict_types=1);

namespace App\Http\Controllers\AI;

use App\Http\Controllers\Controller;
use App\Models\ChatAttachment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ChatAttachmentController extends Controller
{
    /**
     * Raster formats safe to render inline for chat thumbnails. Anything
     * else (PDF, text, and SVG should it ever slip past validation) is
     * forced to download so user uploads never execute on the app origin.
     */
    private const array INLINE_MIME_TYPES = ['image/png', 'image/jpeg', 'image/webp', 'image/gif'];

    /**
     * Download a chat attachment. Only its uploader may read it.
     */
    public function __invoke(Request $request, ChatAttachment $chatAttachment): StreamedResponse
    {
        abort_unless($chatAttachment->user_id === $request->user()?->id, 403);

        $headers = [
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ];

        $disk = Storage::disk($chatAttachment->disk);

        return in_array($chatAttachment->mime_type, self::INLINE_MIME_TYPES, true)
            ? $disk->response($chatAttachment->path, $chatAttachment->original_name, $headers)
            : $disk->download($chatAttachment->path, $chatAttachment->original_name, $headers);
    }
}
